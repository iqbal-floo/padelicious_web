<?php

namespace Tests\Feature;

use App\Enums\PlayerGender;
use App\Models\MatchGame;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Correcting a result after the round was closed: reopen the round, then
 * undo or reset the match and score it again.
 */
class ReopenRoundTest extends TestCase
{
    use RefreshDatabase;

    private Session $session;

    private MatchGame $match;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $host = Player::query()->create([
            'user_id' => $user->id, 'display_name' => 'Host', 'initials' => 'HO', 'gender' => PlayerGender::Male,
        ]);
        $others = collect(['Ana', 'Ben', 'Cid'])->map(fn ($n) => Player::query()->create([
            'display_name' => $n, 'initials' => strtoupper(substr($n, 0, 2)),
        ]));

        $this->session = app(SessionBuilder::class)->create(
            [
                'name' => 'Reopen', 'match_type_code' => 'americano', 'scheduled_date' => '2026-10-18',
                'court_count' => 1, 'scoring_type' => 'tennis', 'tennis_mode' => 'race3', 'golden_point' => true,
            ],
            $others->map(fn ($p) => ['player_id' => $p->id])->all(),
            $host->id,
        );

        Sanctum::actingAs($user);
        $this->match = app(SessionBuilder::class)->generateRound($this->session)->matches->first();
    }

    /** Side A wins a race-to-3 match 3-0. */
    private function playAndComplete(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->postJson("/api/v1/matches/{$this->match->id}/points", ['team' => 'a'])->assertOk();
        }
        $this->postJson("/api/v1/rounds/{$this->match->round_id}/complete")->assertOk();
    }

    public function test_a_completed_round_locks_its_scores(): void
    {
        $this->playAndComplete();

        $this->deleteJson("/api/v1/matches/{$this->match->id}/points")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This round is completed. Reopen it to change its scores.');

        $this->postJson("/api/v1/matches/{$this->match->id}/reset")->assertStatus(409);
    }

    public function test_reopening_lets_the_result_be_corrected(): void
    {
        $this->playAndComplete();

        $this->postJson("/api/v1/rounds/{$this->match->round_id}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        // Undo the winning point: the match is live again at 2-0.
        $this->deleteJson("/api/v1/matches/{$this->match->id}/points")
            ->assertOk()
            ->assertJsonPath('data.status', 'live')
            ->assertJsonPath('data.winner_team', null)
            ->assertJsonPath('data.games_a', 2);

        // Completing again works as before.
        $this->postJson("/api/v1/rounds/{$this->match->round_id}/complete")
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_reset_clears_the_match_and_the_standings(): void
    {
        $this->playAndComplete();
        $this->postJson("/api/v1/rounds/{$this->match->round_id}/reopen")->assertOk();

        $this->postJson("/api/v1/matches/{$this->match->id}/reset")
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.games_a', 0)
            ->assertJsonPath('data.games_b', 0)
            ->assertJsonPath('data.winner_team', null);

        // Points are voided, not deleted.
        $this->assertSame(12, $this->match->points()->count());
        $this->assertSame(0, $this->match->points()->whereNull('voided_at')->count());

        $this->assertSame(0, (int) $this->session->standings()->sum('wins'), 'no one has won anything now');

        // Re-entered the other way round: B wins.
        for ($i = 0; $i < 12; $i++) {
            $this->postJson("/api/v1/matches/{$this->match->id}/points", ['team' => 'b'])->assertOk();
        }
        $this->assertDatabaseHas('matches', ['id' => $this->match->id, 'winner_team' => 'b', 'games_b' => 3]);
    }

    public function test_only_a_completed_round_can_be_reopened(): void
    {
        $this->postJson("/api/v1/rounds/{$this->match->round_id}/reopen")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only a completed round can be reopened.');
    }

    public function test_only_the_host_may_reopen_or_reset(): void
    {
        $this->playAndComplete();

        $stranger = User::factory()->create();
        Player::query()->create(['user_id' => $stranger->id, 'display_name' => 'Zed', 'initials' => 'ZE']);
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/rounds/{$this->match->round_id}/reopen")->assertForbidden();
        $this->postJson("/api/v1/matches/{$this->match->id}/reset")->assertForbidden();
    }
}
