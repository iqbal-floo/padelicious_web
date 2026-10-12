<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Enums\PlayerGender;
use App\Enums\TeamSide;
use App\Models\MatchGame;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use App\Services\ScoringService;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The session's tennis_mode decides when a match is finished, end to end
 * through the event log — including undoing the golden-point decider.
 */
class TennisModeScoringTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(string $tennisMode): Session
    {
        $host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $host->id, 'display_name' => 'Host', 'initials' => 'HO', 'gender' => PlayerGender::Male,
        ]);

        $others = collect(['Ana', 'Ben', 'Cid'])->map(fn ($n) => Player::query()->create([
            'display_name' => $n, 'initials' => strtoupper(substr($n, 0, 2)), 'gender' => PlayerGender::Male,
        ]));

        return app(SessionBuilder::class)->create(
            [
                'name' => "Test {$tennisMode}",
                'match_type_code' => 'americano',
                'scheduled_date' => now()->toDateString(),
                'court_count' => 1,
                'scoring_type' => 'tennis',
                'tennis_mode' => $tennisMode,
                'golden_point' => true,
            ],
            $others->map(fn ($p) => ['player_id' => $p->id])->all(),
            $hostPlayer->id,
        );
    }

    private function firstMatch(Session $session): MatchGame
    {
        return app(SessionBuilder::class)->generateRound($session)->matches->first();
    }

    private function winGames(MatchGame $match, TeamSide $side, int $games): MatchGame
    {
        $scoring = app(ScoringService::class);

        for ($g = 0; $g < $games; $g++) {
            for ($p = 0; $p < 4; $p++) {
                $match = $scoring->recordPoint($match, $side);
            }
        }

        return $match;
    }

    public function test_total_five_finishes_at_three_two(): void
    {
        $match = $this->firstMatch($this->makeSession('total5'));

        $match = $this->winGames($match, TeamSide::A, 2);
        $match = $this->winGames($match, TeamSide::B, 2);
        $this->assertSame(MatchStatus::Live, $match->status, '2-2 of 5 is not over');

        $match = $this->winGames($match, TeamSide::A, 1);

        $this->assertSame(MatchStatus::Finished, $match->status);
        $this->assertSame(TeamSide::A, $match->winner_team);
        $this->assertSame([3, 2], [$match->games_a, $match->games_b]);
    }

    public function test_total_four_level_is_decided_by_one_golden_point(): void
    {
        $match = $this->firstMatch($this->makeSession('total4'));

        $match = $this->winGames($match, TeamSide::A, 2);
        $match = $this->winGames($match, TeamSide::B, 2);

        $this->assertSame(MatchStatus::Live, $match->status, 'all four games played, level: still live');
        $this->assertNull($match->winner_team);

        $match = app(ScoringService::class)->recordPoint($match, TeamSide::B);

        $this->assertSame(MatchStatus::Finished, $match->status);
        $this->assertSame(TeamSide::B, $match->winner_team);
        $this->assertSame([2, 2], [$match->games_a, $match->games_b]);

        $this->assertDatabaseHas('match_points', [
            'match_id' => $match->id,
            'games_a_before' => 2,
            'games_b_before' => 2,
            'is_golden_point' => true,
        ]);
    }

    public function test_undoing_the_decider_reopens_the_match_at_two_two(): void
    {
        $match = $this->firstMatch($this->makeSession('total4'));
        $match = $this->winGames($match, TeamSide::A, 2);
        $match = $this->winGames($match, TeamSide::B, 2);

        $scoring = app(ScoringService::class);
        $match = $scoring->recordPoint($match, TeamSide::A);
        $this->assertSame(MatchStatus::Finished, $match->status);

        $match = $scoring->undoPoint($match);

        $this->assertSame(MatchStatus::Live, $match->status);
        $this->assertNull($match->winner_team);
        $this->assertSame([2, 2], [$match->games_a, $match->games_b]);
    }

    public function test_race_three_finishes_at_three_games(): void
    {
        $match = $this->firstMatch($this->makeSession('race3'));

        $match = $this->winGames($match, TeamSide::B, 3);

        $this->assertSame(MatchStatus::Finished, $match->status);
        $this->assertSame(TeamSide::B, $match->winner_team);
        $this->assertSame([0, 3], [$match->games_a, $match->games_b]);
    }
}
