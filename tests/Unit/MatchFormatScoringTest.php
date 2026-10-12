<?php

namespace Tests\Unit;

use App\Enums\TeamSide;
use App\Models\Session;
use App\Services\MatchFormat;
use App\Services\PadelScoring;
use Tests\TestCase;

/**
 * Match-end rules per format: race to N, total of N games, and the single
 * golden point that decides a level total.
 */
class MatchFormatScoringTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function state(array $overrides = []): array
    {
        return [
            'points_a' => '0',
            'points_b' => '0',
            'games_a' => 0,
            'games_b' => 0,
            'serving' => TeamSide::A,
            ...$overrides,
        ];
    }

    /** One clean game for the scorer from love: four straight points. */
    private function winGame(array $state, TeamSide $scorer, MatchFormat $format): array
    {
        for ($i = 0; $i < 4; $i++) {
            $state = PadelScoring::apply($state, $scorer, goldenPoint: true, format: $format);
        }

        return $state;
    }

    // ---- derivation from the session ----------------------------------------

    public function test_tennis_modes_map_to_race_or_total(): void
    {
        $race = MatchFormat::fromSession(new Session(['scoring_type' => 'tennis', 'tennis_mode' => 'race3']));
        $this->assertSame(MatchFormat::RACE, $race->kind);
        $this->assertSame(3, $race->games);

        $total = MatchFormat::fromSession(new Session(['scoring_type' => 'tennis', 'tennis_mode' => 'total5']));
        $this->assertSame(MatchFormat::TOTAL, $total->kind);
        $this->assertSame(5, $total->games);
    }

    public function test_normal_scoring_still_plays_a_set_to_six(): void
    {
        $format = MatchFormat::fromSession(new Session(['scoring_type' => 'normal', 'points_target' => 24]));

        $this->assertSame(MatchFormat::SET, $format->kind);
        $this->assertSame(6, $format->games);
    }

    public function test_only_an_even_total_can_end_level(): void
    {
        $this->assertTrue(MatchFormat::total(4)->canEndLevel());
        $this->assertFalse(MatchFormat::total(5)->canEndLevel());
        $this->assertFalse(MatchFormat::race(4)->canEndLevel());
    }

    // ---- race -----------------------------------------------------------------

    public function test_race_ends_when_a_side_reaches_the_target(): void
    {
        $format = MatchFormat::race(3);
        $state = $this->winGame($this->state(['games_a' => 2, 'games_b' => 2]), TeamSide::B, $format);

        $this->assertTrue($state['match_won']);
        $this->assertSame(TeamSide::B, $state['winner']);
        $this->assertSame([2, 3], [$state['games_a'], $state['games_b']]);
    }

    // ---- total (odd) ------------------------------------------------------------

    public function test_total_five_ends_after_the_fifth_game_at_three_two(): void
    {
        $format = MatchFormat::total(5);

        // 2-2 after four games: not over, even though both have "won" games.
        $state = $this->winGame($this->state(['games_a' => 2, 'games_b' => 1]), TeamSide::B, $format);
        $this->assertFalse($state['match_won']);
        $this->assertFalse($state['decider_pending']);

        // Fifth game decides it: 3-2.
        $state = $this->winGame($state, TeamSide::A, $format);
        $this->assertTrue($state['match_won']);
        $this->assertSame(TeamSide::A, $state['winner']);
        $this->assertSame([3, 2], [$state['games_a'], $state['games_b']]);
    }

    public function test_total_five_can_end_early_only_by_playing_all_five_games(): void
    {
        // 3-1 is not over: total formats play every game (4-1 or 5-0 are
        // still possible), unlike a race.
        $format = MatchFormat::total(5);
        $state = $this->winGame($this->state(['games_a' => 2, 'games_b' => 1]), TeamSide::A, $format);

        $this->assertSame([3, 1], [$state['games_a'], $state['games_b']]);
        $this->assertFalse($state['match_won']);
    }

    // ---- total (even) with golden-point decider ---------------------------------

    public function test_total_four_at_two_two_waits_for_a_decider(): void
    {
        $format = MatchFormat::total(4);
        $state = $this->winGame($this->state(['games_a' => 2, 'games_b' => 1]), TeamSide::B, $format);

        $this->assertSame([2, 2], [$state['games_a'], $state['games_b']]);
        $this->assertFalse($state['match_won'], 'a level total is not decided yet');
        $this->assertTrue($state['decider_pending']);
        $this->assertSame(['0', '0'], [$state['points_a'], $state['points_b']]);
    }

    public function test_the_decider_point_wins_the_match_without_adding_a_game(): void
    {
        $format = MatchFormat::total(4);
        $state = PadelScoring::apply(
            $this->state(['games_a' => 2, 'games_b' => 2]),
            TeamSide::B,
            goldenPoint: true,
            format: $format,
        );

        $this->assertTrue($state['match_won']);
        $this->assertSame(TeamSide::B, $state['winner']);
        $this->assertSame([2, 2], [$state['games_a'], $state['games_b']], 'the sheet still reads 2-2');
        $this->assertTrue($state['is_golden_point']);
        $this->assertFalse($state['game_won']);
    }

    public function test_total_four_at_three_one_needs_no_decider(): void
    {
        $format = MatchFormat::total(4);
        $state = $this->winGame($this->state(['games_a' => 2, 'games_b' => 1]), TeamSide::A, $format);

        $this->assertSame([3, 1], [$state['games_a'], $state['games_b']]);
        $this->assertTrue($state['match_won']);
        $this->assertSame(TeamSide::A, $state['winner']);
    }

    public function test_the_default_format_is_unchanged_a_set_to_six(): void
    {
        $state = $this->winGame($this->state(['games_a' => 5, 'games_b' => 3]), TeamSide::A, MatchFormat::set());

        $this->assertTrue($state['match_won']);
        $this->assertSame(6, $state['games_a']);
    }
}
