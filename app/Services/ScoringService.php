<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Enums\RoundStatus;
use App\Enums\TeamSide;
use App\Models\MatchGame;
use App\Models\MatchPoint;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists scoring events against the match_points log.
 *
 * match_points is the source of truth: the games and points columns on
 * matches are a derived cache, so undo replays the log rather than
 * guessing backwards.
 */
class ScoringService
{
    /**
     * Record one point. Returns the refreshed match.
     */
    public function recordPoint(
        MatchGame $match,
        TeamSide $scorer,
        ?string $recordedByPlayerId = null,
    ): MatchGame {
        return DB::transaction(function () use ($match, $scorer, $recordedByPlayerId) {
            // Lock the row so two devices on one court cannot interleave.
            $match = MatchGame::query()
                ->whereKey($match->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRoundOpen($match);

            if ($match->status === MatchStatus::Finished) {
                throw new RuntimeException('This match is already finished.');
            }

            if ($match->status === MatchStatus::Abandoned) {
                throw new RuntimeException('This match was abandoned.');
            }

            $session = $match->round->session;
            $goldenPoint = $session->golden_point;
            $format = MatchFormat::forMatch($match);

            $before = [
                'points_a' => $match->points_a,
                'points_b' => $match->points_b,
                'games_a' => $match->games_a,
                'games_b' => $match->games_b,
                'serving' => $match->serving_team,
            ];

            $after = PadelScoring::apply($before, $scorer, $goldenPoint, $format);

            $nextSequence = (int) MatchPoint::query()
                ->where('match_id', $match->id)
                ->max('sequence') + 1;

            // The event log stores the state *before* the point, so a replay
            // can reconstruct every intermediate score.
            MatchPoint::query()->create([
                'match_id' => $match->id,
                'sequence' => $nextSequence,
                'scoring_team' => $scorer,
                'serving_team' => $before['serving'],
                'points_a_before' => $before['points_a'],
                'points_b_before' => $before['points_b'],
                'games_a_before' => $before['games_a'],
                'games_b_before' => $before['games_b'],
                'is_game_point' => $after['is_game_point'],
                'is_golden_point' => $after['is_golden_point'],
                'recorded_by_player_id' => $recordedByPlayerId,
                'recorded_at' => now(),
            ]);

            $this->writeState($match, $after);

            return $match->refresh();
        });
    }

    /**
     * Void the most recent point and rebuild state by replaying the log.
     */
    public function undoPoint(MatchGame $match): MatchGame
    {
        return DB::transaction(function () use ($match) {
            $match = MatchGame::query()
                ->whereKey($match->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRoundOpen($match);

            $last = MatchPoint::query()
                ->where('match_id', $match->id)
                ->whereNull('voided_at')
                ->orderByDesc('sequence')
                ->first();

            if (! $last) {
                throw new RuntimeException('Nothing to undo.');
            }

            $last->forceFill(['voided_at' => now()])->save();

            $this->replay($match);

            return $match->refresh();
        });
    }

    /**
     * Void every point of a match and put it back to not-started, so a
     * wrong result can be re-entered from scratch. The events are voided,
     * not deleted, so the log still shows what happened.
     */
    public function resetMatch(MatchGame $match): MatchGame
    {
        return DB::transaction(function () use ($match) {
            $match = MatchGame::query()
                ->whereKey($match->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRoundOpen($match);

            $first = MatchPoint::query()
                ->where('match_id', $match->id)
                ->orderBy('sequence')
                ->first();

            MatchPoint::query()
                ->where('match_id', $match->id)
                ->whereNull('voided_at')
                ->update(['voided_at' => now()]);

            $match->forceFill([
                'points_a' => '0',
                'points_b' => '0',
                'games_a' => 0,
                'games_b' => 0,
                'serving_team' => $first?->serving_team ?? $match->serving_team,
                'status' => MatchStatus::Scheduled,
                'winner_team' => null,
                'started_at' => null,
                'finished_at' => null,
            ])->save();

            return $match->refresh();
        });
    }

    /**
     * A completed round is closed: its scores change only after the host
     * reopens it, so a stray tap cannot rewrite a finished result.
     */
    private function assertRoundOpen(MatchGame $match): void
    {
        if ($match->round?->status === RoundStatus::Completed) {
            throw new RuntimeException('This round is completed. Reopen it to change its scores.');
        }
    }

    /**
     * Rebuild the cached games and points columns by replaying the log.
     */
    private function replay(MatchGame $match): void
    {
        $session = $match->round->session;
        $goldenPoint = $session->golden_point;
        $format = MatchFormat::forMatch($match);

        $state = [
            'points_a' => '0',
            'points_b' => '0',
            'games_a' => 0,
            'games_b' => 0,
            'serving' => $match->serving_team,
            'game_won' => false,
            'match_won' => false,
            'winner' => null,
        ];

        // The first event's serving_team is the match's original server.
        $first = MatchPoint::query()
            ->where('match_id', $match->id)
            ->orderBy('sequence')
            ->first();

        if ($first) {
            $state['serving'] = $first->serving_team;
        }

        $events = MatchPoint::query()
            ->where('match_id', $match->id)
            ->whereNull('voided_at')
            ->orderBy('sequence')
            ->get();

        foreach ($events as $event) {
            $state = PadelScoring::apply($state, $event->scoring_team, $goldenPoint, $format);
        }

        $this->writeState($match, $state, reopening: true);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(MatchGame $match, array $state, bool $reopening = false): void
    {
        $attributes = [
            'points_a' => $state['points_a'],
            'points_b' => $state['points_b'],
            'games_a' => $state['games_a'],
            'games_b' => $state['games_b'],
            'serving_team' => $state['serving'],
        ];

        if ($state['match_won']) {
            // matches_winner_only_when_finished requires both together.
            $attributes['status'] = MatchStatus::Finished;
            $attributes['winner_team'] = $state['winner'];
            $attributes['finished_at'] = now();
        } elseif ($reopening) {
            // An undo can take a finished match back to live.
            $attributes['status'] = MatchStatus::Live;
            $attributes['winner_team'] = null;
            $attributes['finished_at'] = null;
        } elseif ($match->status === MatchStatus::Scheduled) {
            $attributes['status'] = MatchStatus::Live;
            $attributes['started_at'] = $match->started_at ?? now();
        }

        $match->forceFill($attributes)->save();
    }
}
