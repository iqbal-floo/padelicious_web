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
 *
 * When the match itself ends is the MatchFormat's call: a set to six, a
 * race to N, or a fixed total of N games. For an even total that ends
 * level, one further golden point decides the match.
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
     * @return array{points_a: string, points_b: string, games_a: int, games_b: int, serving: ?TeamSide, game_won: bool, match_won: bool, winner: ?TeamSide, decider_pending: bool, is_game_point: bool, is_golden_point: bool}
     */
    public static function apply(
        array $state,
        TeamSide $scorer,
        bool $goldenPoint,
        ?MatchFormat $format = null,
    ): array {
        $format ??= MatchFormat::set();
        $isA = $scorer === TeamSide::A;

        $gamesA = $state['games_a'];
        $gamesB = $state['games_b'];
        $serving = $state['serving'] ?? null;

        // Every game played and the score level: this one point settles
        // the match. No game is added, so the sheet still reads 2-2.
        if ($format->deciderDue($gamesA, $gamesB)) {
            return [
                'points_a' => '0',
                'points_b' => '0',
                'games_a' => $gamesA,
                'games_b' => $gamesB,
                'serving' => $serving,
                'game_won' => false,
                'match_won' => true,
                'winner' => $scorer,
                'decider_pending' => false,
                'is_game_point' => true,
                'is_golden_point' => true,
            ];
        }

        $mine = $isA ? $state['points_a'] : $state['points_b'];
        $theirs = $isA ? $state['points_b'] : $state['points_a'];

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

        $matchWon = $gameWon && $format->wonOnGames($gamesA, $gamesB);

        return [
            'points_a' => $pointsA,
            'points_b' => $pointsB,
            'games_a' => $gamesA,
            'games_b' => $gamesB,
            'serving' => $serving,
            'game_won' => $gameWon,
            'match_won' => $matchWon,
            'winner' => $matchWon ? ($gamesA > $gamesB ? TeamSide::A : TeamSide::B) : null,
            'decider_pending' => $gameWon && $format->deciderDue($gamesA, $gamesB),
            'is_game_point' => $isGamePoint,
            'is_golden_point' => $isGoldenPoint,
        ];
    }
}
