<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoundStatus;
use App\Exceptions\InvalidRoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoundRequest;
use App\Http\Resources\RoundResource;
use App\Models\Round;
use App\Models\Session;
use App\Services\SessionBuilder;
use App\Services\StandingsCalculator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RoundController extends Controller
{
    /**
     * GET /api/v1/sessions/{session}/rounds
     *
     * Rounds with their matches, teams, and players — the data behind
     * the live court screen.
     */
    public function index(Session $session): AnonymousResourceCollection
    {
        $rounds = $session->rounds()
            ->with([
                'matches.court',
                'matches.teams.players',
            ])
            ->orderBy('round_number')
            ->get();

        return RoundResource::collection($rounds);
    }

    /**
     * POST /api/v1/sessions/{session}/rounds
     *
     * No body: the match type's algorithm generates the next round.
     * With `matches`: the host enters the round by hand (see
     * StoreRoundRequest), optionally at an explicit `round_number`.
     */
    public function store(
        StoreRoundRequest $request,
        Session $session,
        SessionBuilder $builder,
    ): Response|HttpResponse {
        $this->authorize('manage', $session);

        try {
            $round = $request->has('matches')
                ? $builder->createRound(
                    $session,
                    $request->validated('matches'),
                    $request->validated('round_number'),
                )
                : $builder->generateRound($session);
        } catch (InvalidRoundException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_CONFLICT,
            );
        }

        return (new RoundResource($round->load(['matches.court', 'matches.teams.players'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * POST /api/v1/rounds/{round}/reopen
     *
     * Takes a completed round back to in_progress so its scores can be
     * corrected. Standings are recalculated whenever a score changes, and
     * completing it again works as before.
     */
    public function reopen(Request $request, Round $round): RoundResource|Response
    {
        $session = $round->session;
        $this->authorize('manage', $session);

        if ($round->status !== RoundStatus::Completed) {
            return response()->json(
                ['message' => 'Only a completed round can be reopened.'],
                Response::HTTP_CONFLICT,
            );
        }

        $round->forceFill([
            'status' => RoundStatus::InProgress,
            'completed_at' => null,
        ])->save();

        return new RoundResource($round->load(['matches.court', 'matches.teams.players']));
    }

    /**
     * POST /api/v1/rounds/{round}/complete
     */
    public function complete(
        Request $request,
        Round $round,
        StandingsCalculator $standings,
    ): RoundResource {
        $session = $round->session;
        $this->authorize('manage', $session);

        $round->forceFill([
            'status' => RoundStatus::Completed,
            'completed_at' => now(),
        ])->save();

        // A hand-entered schedule queues rounds as pending; the next one
        // takes the courts as soon as this one is done.
        $session->rounds()
            ->where('status', RoundStatus::Pending->value)
            ->orderBy('round_number')
            ->first()
            ?->forceFill(['status' => RoundStatus::InProgress])
            ->save();

        $standings->recalculate($session);

        return new RoundResource($round->load(['matches.court', 'matches.teams.players']));
    }
}
