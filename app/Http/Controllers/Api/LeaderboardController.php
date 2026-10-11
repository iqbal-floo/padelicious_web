<?php

namespace App\Http\Controllers\Api;

use App\Enums\LeaderboardSort;
use App\Http\Controllers\Controller;
use App\Http\Resources\StandingResource;
use App\Models\Session;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LeaderboardController extends Controller
{
    /**
     * GET /api/v1/sessions/{session}/leaderboard
     *
     * Ordered by the session's own leaderboard_sort preference.
     */
    public function index(Session $session): AnonymousResourceCollection
    {
        $primary = $session->leaderboard_sort === LeaderboardSort::Wins
            ? 'wins'
            : 'points';

        $standings = $session->standings()
            ->with('participant')
            ->orderByDesc($primary)
            ->orderByDesc($primary === 'wins' ? 'points' : 'wins')
            ->orderByDesc('games_won')
            ->get();

        return StandingResource::collection($standings);
    }
}
