<?php

namespace App\Http\Controllers\Api;

use App\Enums\TeamSide;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordPointRequest;
use App\Http\Resources\MatchResource;
use App\Models\MatchGame;
use App\Services\ScoringService;
use App\Services\StandingsCalculator;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ScoreController extends Controller
{
    public function __construct(
        private readonly ScoringService $scoring,
        private readonly StandingsCalculator $standings,
    ) {}

    /**
     * POST /api/v1/matches/{match}/points
     */
    public function store(RecordPointRequest $request, MatchGame $match): Response
    {
        $session = $match->round->session;
        $this->authorize('manage', $session);

        try {
            $match = $this->scoring->recordPoint(
                $match,
                TeamSide::from($request->string('team')->toString()),
                $request->user()->player?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_CONFLICT,
            );
        }

        // Keep the leaderboard in step once a match is decided.
        if ($match->winner_team !== null) {
            $this->standings->recalculate($session);
        }

        return $this->matchResponse($match);
    }

    /**
     * DELETE /api/v1/matches/{match}/points — undoes the last point.
     */
    public function destroy(Request $request, MatchGame $match): Response
    {
        $session = $match->round->session;
        $this->authorize('manage', $session);

        try {
            $match = $this->scoring->undoPoint($match);
        } catch (RuntimeException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_CONFLICT,
            );
        }

        $this->standings->recalculate($session);

        return $this->matchResponse($match);
    }

    private function matchResponse(MatchGame $match): Response
    {
        return (new MatchResource($match->load(['court', 'teams.players'])))->response();
    }
}
