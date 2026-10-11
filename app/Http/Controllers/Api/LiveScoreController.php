<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MatchGame;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public, read-only live score for a single match.
 *
 * Deliberately unauthenticated: a spectator only needs the match uuid,
 * which is unguessable. Nothing here can change a score.
 */
class LiveScoreController extends Controller
{
    /**
     * GET /api/v1/live/matches/{match} — one snapshot as JSON.
     */
    public function show(MatchGame $match): JsonResponse
    {
        return response()->json(['data' => $this->snapshot($match)]);
    }

    /**
     * GET /api/v1/live/matches/{match}/stream — Server-Sent Events.
     *
     * Polls the database server-side and pushes only when the score
     * actually changes, so an idle match costs the client nothing.
     */
    public function stream(MatchGame $match): StreamedResponse
    {
        $matchId = $match->id;

        $response = new StreamedResponse(function () use ($matchId) {
            // No time limit: this request is meant to stay open.
            @set_time_limit(0);
            ignore_user_abort(false);

            $lastPayload = null;
            $started = time();
            $maxSeconds = 1800; // Client reconnects automatically after this.

            while (true) {
                if (connection_aborted() || (time() - $started) > $maxSeconds) {
                    break;
                }

                // A streamed response lives for minutes, so the connection
                // must be refreshed or every poll re-reads the same snapshot
                // from a stale transaction/identity map.
                DB::connection()->reconnect();

                $match = MatchGame::query()
                    ->with(['court', 'teams.players', 'round.session'])
                    ->find($matchId);

                if (! $match) {
                    $this->emit('error', ['message' => 'Match not found.']);
                    break;
                }

                $snapshot = $this->snapshot($match);
                // updated_at changes every tick; exclude it from the diff.
                $comparable = $snapshot;
                unset($comparable['updated_at']);
                $payload = json_encode($comparable);

                if ($payload !== $lastPayload) {
                    $this->emit('score', $snapshot);
                    $lastPayload = $payload;
                }

                // A comment line doubles as a keep-alive through proxies.
                echo ": keep-alive\n\n";
                $this->flush();

                sleep(2);
            }

            $this->emit('closed', ['message' => 'Stream ended. Reconnecting.']);
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-transform');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function emit(string $event, array $data): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($data)."\n\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        @flush();
    }

    /**
     * The spectator-visible state of a match.
     *
     * @return array<string, mixed>
     */
    private function snapshot(MatchGame $match): array
    {
        $match->loadMissing(['court', 'teams.players', 'round.session']);

        $teams = $match->teams->sortBy(fn ($t) => $t->side->value)->values();

        return [
            'match_id' => $match->id,
            'status' => $match->status?->value,
            'games' => [
                'a' => $match->games_a,
                'b' => $match->games_b,
            ],
            'points' => [
                'a' => $match->points_a,
                'b' => $match->points_b,
            ],
            'serving_team' => $match->serving_team?->value,
            'winner_team' => $match->winner_team?->value,
            'started_at' => $match->started_at?->toIso8601String(),
            'finished_at' => $match->finished_at?->toIso8601String(),
            'court' => [
                'number' => $match->court?->court_number,
                'label' => $match->court?->label,
            ],
            'session' => [
                'id' => $match->round?->session?->id,
                'name' => $match->round?->session?->name,
                'golden_point' => $match->round?->session?->golden_point,
            ],
            'round_number' => $match->round?->round_number,
            'teams' => $teams->map(fn ($team) => [
                'side' => $team->side?->value,
                'players' => $team->players
                    ->sortBy(fn ($p) => $p->pivot->slot)
                    ->map(fn ($p) => [
                        'display_name' => $p->display_name_snapshot,
                        'initials' => $p->initials_snapshot,
                        'avatar_color_index' => $p->avatar_color_index_snapshot,
                    ])->values(),
            ])->values(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
