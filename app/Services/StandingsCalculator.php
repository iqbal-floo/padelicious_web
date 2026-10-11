<?php

namespace App\Services;

use App\Enums\LeaderboardSort;
use App\Enums\MatchStatus;
use App\Enums\TeamSide;
use App\Models\Session;
use App\Models\SessionStanding;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes session_standings from finished matches.
 *
 * Points are games won, the Americano convention: every game a player's
 * team takes adds one point to that player.
 */
class StandingsCalculator
{
    public function recalculate(Session $session): void
    {
        DB::transaction(function () use ($session) {
            $rows = [];

            foreach ($session->participants as $participant) {
                $rows[$participant->id] = [
                    'points' => 0,
                    'wins' => 0,
                    'losses' => 0,
                    'draws' => 0,
                    'matches_played' => 0,
                    'games_won' => 0,
                    'games_lost' => 0,
                ];
            }

            $matches = $session->rounds()
                ->with('matches.teams.teamPlayers')
                ->get()
                ->flatMap->matches
                ->where('status', MatchStatus::Finished);

            foreach ($matches as $match) {
                foreach ($match->teams as $team) {
                    $isA = $team->side === TeamSide::A;
                    $own = $isA ? $match->games_a : $match->games_b;
                    $other = $isA ? $match->games_b : $match->games_a;

                    foreach ($team->teamPlayers as $teamPlayer) {
                        $id = $teamPlayer->participant_id;

                        if (! isset($rows[$id])) {
                            continue;
                        }

                        $rows[$id]['matches_played']++;
                        $rows[$id]['games_won'] += $own;
                        $rows[$id]['games_lost'] += $other;
                        $rows[$id]['points'] += $own;

                        if ($own > $other) {
                            $rows[$id]['wins']++;
                        } elseif ($own < $other) {
                            $rows[$id]['losses']++;
                        } else {
                            $rows[$id]['draws']++;
                        }
                    }
                }
            }

            foreach ($rows as $participantId => $values) {
                SessionStanding::query()->updateOrCreate(
                    ['session_id' => $session->id, 'participant_id' => $participantId],
                    [...$values, 'updated_at' => now()],
                );
            }

            $this->assignRanks($session);
        });
    }

    /**
     * Rank by the session's configured sort, tie-breaking on the other
     * metric and then games won.
     */
    private function assignRanks(Session $session): void
    {
        $primary = $session->leaderboard_sort === LeaderboardSort::Wins ? 'wins' : 'points';
        $secondary = $primary === 'wins' ? 'points' : 'wins';

        $standings = SessionStanding::query()
            ->where('session_id', $session->id)
            ->orderByDesc($primary)
            ->orderByDesc($secondary)
            ->orderByDesc('games_won')
            ->get();

        foreach ($standings as $index => $standing) {
            $standing->forceFill(['rank' => $index + 1])->save();
        }
    }
}
