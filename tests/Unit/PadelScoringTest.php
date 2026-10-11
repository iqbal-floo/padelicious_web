<?php

namespace Tests\Unit;

use App\Enums\TeamSide;
use App\Services\PadelScoring;
use PHPUnit\Framework\TestCase;

class PadelScoringTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function state(array $overrides = []): array
    {
        return array_merge([
            'points_a' => '0',
            'points_b' => '0',
            'games_a' => 0,
            'games_b' => 0,
            'serving' => TeamSide::A,
        ], $overrides);
    }

    public function test_points_advance_through_the_sequence(): void
    {
        $state = $this->state();

        foreach (['15', '30', '40'] as $expected) {
            $state = PadelScoring::apply($state, TeamSide::A, goldenPoint: true);
            $this->assertSame($expected, $state['points_a']);
            $this->assertFalse($state['game_won']);
        }
    }

    public function test_scoring_at_40_wins_the_game_and_resets_points(): void
    {
        $result = PadelScoring::apply(
            $this->state(['points_a' => '40', 'points_b' => '30']),
            TeamSide::A,
            goldenPoint: true
        );

        $this->assertTrue($result['game_won']);
        $this->assertSame(1, $result['games_a']);
        $this->assertSame(0, $result['games_b']);
        $this->assertSame('0', $result['points_a']);
        $this->assertSame('0', $result['points_b']);
    }

    public function test_serve_alternates_when_a_game_is_won(): void
    {
        $result = PadelScoring::apply(
            $this->state(['points_a' => '40', 'serving' => TeamSide::A]),
            TeamSide::A,
            goldenPoint: true
        );

        $this->assertSame(TeamSide::B, $result['serving']);
    }

    public function test_serve_does_not_change_mid_game(): void
    {
        $result = PadelScoring::apply(
            $this->state(['serving' => TeamSide::B]),
            TeamSide::A,
            goldenPoint: true
        );

        $this->assertSame(TeamSide::B, $result['serving']);
    }

    public function test_golden_point_on_decides_the_game_at_deuce(): void
    {
        $result = PadelScoring::apply(
            $this->state(['points_a' => '40', 'points_b' => '40']),
            TeamSide::B,
            goldenPoint: true
        );

        $this->assertTrue($result['game_won']);
        $this->assertTrue($result['is_golden_point']);
        $this->assertSame(1, $result['games_b']);
    }

    public function test_golden_point_off_awards_advantage_at_deuce(): void
    {
        $result = PadelScoring::apply(
            $this->state(['points_a' => '40', 'points_b' => '40']),
            TeamSide::A,
            goldenPoint: false
        );

        $this->assertFalse($result['game_won']);
        $this->assertFalse($result['is_golden_point']);
        $this->assertSame(PadelScoring::ADVANTAGE, $result['points_a']);
        $this->assertSame('40', $result['points_b']);
    }

    public function test_converting_advantage_wins_the_game(): void
    {
        $result = PadelScoring::apply(
            $this->state(['points_a' => PadelScoring::ADVANTAGE, 'points_b' => '40']),
            TeamSide::A,
            goldenPoint: false
        );

        $this->assertTrue($result['game_won']);
        $this->assertSame(1, $result['games_a']);
    }

    /**
     * The client would crash here; the server returns to deuce.
     */
    public function test_losing_advantage_returns_to_deuce(): void
    {
        $result = PadelScoring::apply(
            $this->state(['points_a' => PadelScoring::ADVANTAGE, 'points_b' => '40']),
            TeamSide::B,
            goldenPoint: false
        );

        $this->assertFalse($result['game_won']);
        $this->assertSame('40', $result['points_a']);
        $this->assertSame('40', $result['points_b']);
    }

    public function test_deuce_can_cycle_repeatedly(): void
    {
        $state = $this->state(['points_a' => '40', 'points_b' => '40']);

        // A takes advantage, loses it, B takes advantage, loses it.
        $state = PadelScoring::apply($state, TeamSide::A, goldenPoint: false);
        $this->assertSame(PadelScoring::ADVANTAGE, $state['points_a']);

        $state = PadelScoring::apply($state, TeamSide::B, goldenPoint: false);
        $this->assertSame('40', $state['points_a']);
        $this->assertSame('40', $state['points_b']);

        $state = PadelScoring::apply($state, TeamSide::B, goldenPoint: false);
        $this->assertSame(PadelScoring::ADVANTAGE, $state['points_b']);

        $state = PadelScoring::apply($state, TeamSide::A, goldenPoint: false);
        $this->assertSame('40', $state['points_a']);
        $this->assertSame('40', $state['points_b']);
        $this->assertSame(0, $state['games_a']);
        $this->assertSame(0, $state['games_b']);
    }

    public function test_game_point_flag_is_set_only_when_the_game_can_be_won(): void
    {
        $notGamePoint = PadelScoring::apply(
            $this->state(['points_a' => '30']),
            TeamSide::A,
            goldenPoint: true
        );
        $this->assertFalse($notGamePoint['is_game_point']);

        $gamePoint = PadelScoring::apply(
            $this->state(['points_a' => '40', 'points_b' => '0']),
            TeamSide::A,
            goldenPoint: true
        );
        $this->assertTrue($gamePoint['is_game_point']);
    }

    public function test_sixth_game_wins_the_set(): void
    {
        $result = PadelScoring::apply(
            $this->state(['points_a' => '40', 'games_a' => 5, 'games_b' => 3]),
            TeamSide::A,
            goldenPoint: true
        );

        $this->assertTrue($result['set_won']);
        $this->assertSame(TeamSide::A, $result['winner']);
        $this->assertSame(6, $result['games_a']);
    }

    public function test_set_is_not_won_before_six_games(): void
    {
        $result = PadelScoring::apply(
            $this->state(['points_a' => '40', 'games_a' => 4]),
            TeamSide::A,
            goldenPoint: true
        );

        $this->assertFalse($result['set_won']);
        $this->assertNull($result['winner']);
    }

    public function test_a_full_game_from_love_takes_four_points(): void
    {
        $state = $this->state();

        for ($i = 0; $i < 4; $i++) {
            $state = PadelScoring::apply($state, TeamSide::B, goldenPoint: true);
        }

        $this->assertSame(1, $state['games_b'], 'four consecutive points should win one game');
        $this->assertSame('0', $state['points_b']);
    }
}
