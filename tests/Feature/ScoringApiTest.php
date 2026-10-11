<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Enums\TeamSide;
use App\Models\MatchGame;
use App\Models\MatchPoint;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScoringApiTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $this->host->id,
            'display_name' => 'Host',
            'initials' => 'HO',
        ]);

        $others = collect(['AA', 'BB', 'CC'])->map(
            fn ($initials) => Player::query()->create([
                'display_name' => $initials,
                'initials' => $initials,
            ])
        );

        $this->session = app(SessionBuilder::class)->create(
            [
                'name' => 'Test Session',
                'match_type_code' => 'americano',
                'scheduled_date' => now()->toDateString(),
                'court_count' => 1,
                'scoring_type' => 'normal',
                'points_target' => 24,
                'golden_point' => true,
            ],
            $others->map(fn ($p) => ['player_id' => $p->id])->all(),
            $hostPlayer->id,
        );
    }

    private function firstMatch(): MatchGame
    {
        Sanctum::actingAs($this->host);
        $this->postJson("/api/v1/sessions/{$this->session->id}/rounds")->assertCreated();

        return MatchGame::query()->firstOrFail();
    }

    public function test_generating_a_round_creates_a_match_with_two_teams(): void
    {
        Sanctum::actingAs($this->host);

        $response = $this->postJson("/api/v1/sessions/{$this->session->id}/rounds");

        $response->assertCreated()
            ->assertJsonPath('data.round_number', 1)
            ->assertJsonCount(1, 'data.matches')
            ->assertJsonCount(2, 'data.matches.0.teams');

        // Four players, two per side.
        $this->assertCount(2, $response->json('data.matches.0.teams.0.players'));
        $this->assertCount(2, $response->json('data.matches.0.teams.1.players'));
    }

    public function test_a_round_cannot_be_generated_while_one_is_open(): void
    {
        Sanctum::actingAs($this->host);

        $this->postJson("/api/v1/sessions/{$this->session->id}/rounds")->assertCreated();
        $this->postJson("/api/v1/sessions/{$this->session->id}/rounds")->assertConflict();
    }

    public function test_recording_a_point_advances_the_score_and_logs_an_event(): void
    {
        $match = $this->firstMatch();

        $response = $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a']);

        $response->assertOk()->assertJsonPath('data.points_a', '15');

        $this->assertDatabaseCount('match_points', 1);
        $this->assertDatabaseHas('match_points', [
            'match_id' => $match->id,
            'sequence' => 1,
            'scoring_team' => 'a',
            // The log stores the state *before* the point.
            'points_a_before' => '0',
        ]);
    }

    public function test_recording_a_point_moves_a_scheduled_match_to_live(): void
    {
        $match = $this->firstMatch();
        $this->assertSame(MatchStatus::Scheduled, $match->status);

        $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertOk();

        $this->assertSame(MatchStatus::Live, $match->refresh()->status);
    }

    public function test_four_points_win_a_game(): void
    {
        $match = $this->firstMatch();

        foreach (range(1, 4) as $i) {
            $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertOk();
        }

        $match->refresh();
        $this->assertSame(1, $match->games_a);
        $this->assertSame('0', $match->points_a);
    }

    public function test_undo_restores_the_previous_score_and_voids_the_event(): void
    {
        $match = $this->firstMatch();

        $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertOk();
        $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertOk();
        $this->assertSame('30', $match->refresh()->points_a);

        $this->deleteJson("/api/v1/matches/{$match->id}/points")
            ->assertOk()
            ->assertJsonPath('data.points_a', '15');

        // The event is voided, not deleted: the log stays append-only.
        $this->assertDatabaseCount('match_points', 2);
        $this->assertSame(1, MatchPoint::query()->whereNotNull('voided_at')->count());
    }

    public function test_undo_replays_the_log_across_a_game_boundary(): void
    {
        $match = $this->firstMatch();

        // Win a game, then undo the deciding point.
        foreach (range(1, 4) as $i) {
            $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertOk();
        }
        $this->assertSame(1, $match->refresh()->games_a);

        $this->deleteJson("/api/v1/matches/{$match->id}/points")->assertOk();

        $match->refresh();
        $this->assertSame(0, $match->games_a, 'the game should be given back');
        $this->assertSame('40', $match->points_a);
    }

    public function test_undo_with_no_points_returns_conflict(): void
    {
        $match = $this->firstMatch();

        $this->deleteJson("/api/v1/matches/{$match->id}/points")
            ->assertConflict()
            ->assertJsonPath('message', 'Nothing to undo.');
    }

    public function test_winning_the_sixth_game_finishes_the_match(): void
    {
        $match = $this->firstMatch();

        // 6 games x 4 points.
        foreach (range(1, 24) as $i) {
            $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertOk();
        }

        $match->refresh();
        $this->assertSame(6, $match->games_a);
        $this->assertSame(MatchStatus::Finished, $match->status);
        $this->assertSame(TeamSide::A, $match->winner_team);
    }

    public function test_scoring_a_finished_match_returns_conflict(): void
    {
        $match = $this->firstMatch();

        foreach (range(1, 24) as $i) {
            $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertOk();
        }

        $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])
            ->assertConflict()
            ->assertJsonPath('message', 'This match is already finished.');
    }

    public function test_finishing_a_match_updates_the_leaderboard(): void
    {
        $match = $this->firstMatch();

        foreach (range(1, 24) as $i) {
            $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertOk();
        }

        $response = $this->getJson("/api/v1/sessions/{$this->session->id}/leaderboard")->assertOk();

        // Winners took 6 games each; points follow games won.
        $this->assertSame(1, $response->json('data.0.rank'));
        $this->assertSame(6, $response->json('data.0.points'));
        $this->assertSame(1, $response->json('data.0.wins'));
        $this->assertSame(100.0, (float) $response->json('data.0.win_rate'));
    }

    public function test_the_team_field_is_validated(): void
    {
        $match = $this->firstMatch();

        $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'c'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('team');

        $this->postJson("/api/v1/matches/{$match->id}/points", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('team');
    }

    public function test_a_non_participant_cannot_score(): void
    {
        $match = $this->firstMatch();

        $stranger = User::factory()->create();
        Player::query()->create([
            'user_id' => $stranger->id,
            'display_name' => 'Stranger',
            'initials' => 'ST',
        ]);

        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertForbidden();
    }

    public function test_scoring_requires_authentication(): void
    {
        $match = $this->firstMatch();

        app('auth')->forgetGuards();

        $this->postJson("/api/v1/matches/{$match->id}/points", ['team' => 'a'])->assertUnauthorized();
    }
}
