<?php

namespace App\Services;

use App\Enums\TeamSide;

/**
 * Pure padel scoring rules — no database, no models, so the logic can be
 * unit tested in isolation.
 *
 * Mirrors AppState.scorePoint in the Flutter client
 * (padelicious_app/lib/state/app_state.dart):
 *   0 -> 15 -> 30 -> 40, then the game.
 *   At 40-40 with golden point ON  : next point wins the game (sudden death).
 *   At 40-40 with golden point OFF : scorer takes advantage ("GP").
 *
 * The client has no rule for losing an advantage (it would index past the
 * end of its point sequence), so this implements the standard tennis
 * behaviour: the opponent scoring cancels the advantage back to 40-40.
 */
final class PadelScoring
{
    public const SEQUENCE = ['0', '15', '30', '40'];

    public const ADVANTAGE = 'GP';

    /**
     * Games needed to win a set, matching the client's set-win toast.
     */
    public const GAMES_TO_WIN_SET = 6;

    /**
     * Apply one point and return the resulting state.
     *
     * @param  array{points_a: string, points_b: string, games_a: int, games_b: int, serving: ?TeamSide}  $state
     * @return array{points_a: string, points_b: string, games_a: int, games_b: int, serving: ?TeamSide, game_won: bool, set_won: bool, winner: ?TeamSide, is_game_point: bool, is_golden_point: bool}
     */
    public static function apply(array $state, TeamSide $scorer, bool $goldenPoint): array
    {
        $isA = $scorer === TeamSide::A;

        $mine = $isA ? $state['points_a'] : $state['points_b'];
        $theirs = $isA ? $state['points_b'] : $state['points_a'];

        $gamesA = $state['games_a'];
        $gamesB = $state['games_b'];
        $serving = $state['serving'] ?? null;

        // Was this a game-deciding point? (Recorded on the event log.)
        $atDeuce = $mine === '40' && $theirs === '40';
        $isGamePoint = $mine === '40' || $mine === self::ADVANTAGE;
        $isGoldenPoint = $atDeuce && $goldenPoint;

        $pointsA = $state['points_a'];
        $pointsB = $state['points_b'];
        $gameWon = false;

        if ($atDeuce && ! $goldenPoint) {
            // Advantage to the scorer.
            $pointsA = $isA ? self::ADVANTAGE : '40';
            $pointsB = $isA ? '40' : self::ADVANTAGE;
        } elseif ($theirs === self::ADVANTAGE) {
            // Opponent held advantage and lost it: back to deuce.
            $pointsA = '40';
            $pointsB = '40';
        } elseif ($mine === self::ADVANTAGE || $mine === '40') {
            $gameWon = true;
        } else {
            $next = self::SEQUENCE[array_search($mine, self::SEQUENCE, true) + 1];
            $pointsA = $isA ? $next : $pointsA;
            $pointsB = $isA ? $pointsB : $next;
        }

        if ($gameWon) {
            $isA ? $gamesA++ : $gamesB++;
            $pointsA = '0';
            $pointsB = '0';
            // Serve alternates each game.
            $serving = $serving === TeamSide::A ? TeamSide::B : TeamSide::A;
        }

        $setWon = $gameWon
            && ($gamesA >= self::GAMES_TO_WIN_SET || $gamesB >= self::GAMES_TO_WIN_SET);

        return [
            'points_a' => $pointsA,
            'points_b' => $pointsB,
            'games_a' => $gamesA,
            'games_b' => $gamesB,
            'serving' => $serving,
            'game_won' => $gameWon,
            'set_won' => $setWon,
            'winner' => $setWon ? ($gamesA > $gamesB ? TeamSide::A : TeamSide::B) : null,
            'is_game_point' => $isGamePoint,
            'is_golden_point' => $isGoldenPoint,
        ];
    }
}
