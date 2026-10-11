<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoundStatus;
use App\Http\Controllers\Controller;
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
     * POST /api/v1/sessions/{session}/rounds — generates the next round.
     */
    public function store(
        Request $request,
        Session $session,
        SessionBuilder $builder,
    ): Response|HttpResponse {
        $this->authorize('manage', $session);

        try {
            $round = $builder->generateRound($session);
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

        $standings->recalculate($session);

        return new RoundResource($round->load(['matches.court', 'matches.teams.players']));
    }
}
