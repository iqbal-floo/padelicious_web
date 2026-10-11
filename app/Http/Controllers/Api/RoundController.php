<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoundResource;
use App\Models\Session;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
