<?php

namespace Tests\Feature;

use App\Enums\ParticipantStatus;
use App\Enums\SessionStatus;
use App\Models\Player;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->player = Player::query()->create([
            'user_id' => $this->user->id,
            'display_name' => 'Host',
            'initials' => 'HO',
        ]);

        Sanctum::actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Friday Night',
            'match_type_code' => 'americano',
            'scheduled_date' => '2026-10-20',
            'court_count' => 2,
            'scoring_type' => 'normal',
            'points_target' => 24,
        ], $overrides);
    }

    public function test_a_session_is_created_with_courts_and_the_host_on_the_roster(): void
    {
        $response = $this->postJson('/api/v1/sessions', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Friday Night')
            ->assertJsonPath('data.status', SessionStatus::Draft->value)
            ->assertJsonPath('data.court_count', 2);

        $id = $response->json('data.id');

        // court_count courts are created up front.
        $this->assertDatabaseCount('courts', 2);

        // The host is always a participant, with the host role.
        $this->assertDatabaseHas('session_participants', [
            'session_id' => $id,
            'player_id' => $this->player->id,
            'role' => 'host',
        ]);
    }

    public function test_participants_can_be_supplied_at_creation(): void
    {
        $others = collect(['AA', 'BB'])->map(
            fn ($i) => Player::query()->create(['display_name' => $i, 'initials' => $i])
        );

        $response = $this->postJson('/api/v1/sessions', $this->payload([
            'participants' => $others->map(fn ($p) => ['player_id' => $p->id])->all(),
        ]));

        // Two guests plus the host.
        $response->assertCreated()->assertJsonCount(3, 'data.participants');
    }

    public function test_the_host_is_not_duplicated_when_also_listed(): void
    {
        $response = $this->postJson('/api/v1/sessions', $this->payload([
            'participants' => [['player_id' => $this->player->id]],
        ]));

        $response->assertCreated()->assertJsonCount(1, 'data.participants');
    }

    public function test_normal_scoring_rejects_a_tennis_mode(): void
    {
        $this->postJson('/api/v1/sessions', $this->payload([
            'scoring_type' => 'normal',
            'tennis_mode' => 'race5',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tennis_mode');
    }

    public function test_tennis_scoring_rejects_a_points_target(): void
    {
        $this->postJson('/api/v1/sessions', $this->payload([
            'scoring_type' => 'tennis',
            'points_target' => 24,
            'tennis_mode' => 'race5',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('points_target');
    }

    public function test_invalid_points_target_is_rejected(): void
    {
        $this->postJson('/api/v1/sessions', $this->payload(['points_target' => 25]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('points_target');
    }

    public function test_court_count_is_capped_at_four(): void
    {
        $this->postJson('/api/v1/sessions', $this->payload(['court_count' => 5]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('court_count');
    }

    public function test_unknown_match_type_is_rejected(): void
    {
        $this->postJson('/api/v1/sessions', $this->payload(['match_type_code' => 'nope']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('match_type_code');
    }

    public function test_a_user_without_a_player_profile_cannot_create_a_session(): void
    {
        $orphan = User::factory()->create();
        Sanctum::actingAs($orphan);

        $this->postJson('/api/v1/sessions', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Your account has no player profile yet.');
    }

    public function test_a_participant_can_be_added_then_withdrawn(): void
    {
        $id = $this->postJson('/api/v1/sessions', $this->payload())->json('data.id');
        $guest = Player::query()->create(['display_name' => 'Guest', 'initials' => 'GU']);

        $this->postJson("/api/v1/sessions/{$id}/participants", ['player_id' => $guest->id])
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Guest');

        $participantId = SessionParticipant::query()
            ->where('player_id', $guest->id)->firstOrFail()->id;

        $this->deleteJson("/api/v1/sessions/{$id}/participants/{$participantId}")
            ->assertOk()
            ->assertJsonPath('data.status', ParticipantStatus::Withdrawn->value);

        // Withdrawn, not deleted — match history must survive.
        $this->assertDatabaseHas('session_participants', [
            'id' => $participantId,
            'status' => 'withdrawn',
        ]);
    }

    public function test_adding_the_same_player_twice_returns_conflict(): void
    {
        $id = $this->postJson('/api/v1/sessions', $this->payload())->json('data.id');
        $guest = Player::query()->create(['display_name' => 'Guest', 'initials' => 'GU']);

        $this->postJson("/api/v1/sessions/{$id}/participants", ['player_id' => $guest->id])->assertCreated();
        $this->postJson("/api/v1/sessions/{$id}/participants", ['player_id' => $guest->id])->assertConflict();
    }

    public function test_a_stranger_cannot_modify_the_roster(): void
    {
        $id = $this->postJson('/api/v1/sessions', $this->payload())->json('data.id');
        $guest = Player::query()->create(['display_name' => 'Guest', 'initials' => 'GU']);

        $stranger = User::factory()->create();
        Player::query()->create([
            'user_id' => $stranger->id,
            'display_name' => 'Stranger',
            'initials' => 'ST',
        ]);
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/sessions/{$id}/participants", ['player_id' => $guest->id])
            ->assertForbidden();
    }

    public function test_a_round_needs_four_active_players(): void
    {
        $id = $this->postJson('/api/v1/sessions', $this->payload())->json('data.id');

        // Only the host is on the roster.
        $this->postJson("/api/v1/sessions/{$id}/rounds")
            ->assertConflict()
            ->assertJsonPath('message', 'At least 4 active players are required.');
    }

    public function test_completing_a_session_sets_status_and_timestamp(): void
    {
        $id = $this->postJson('/api/v1/sessions', $this->payload())->json('data.id');

        $this->postJson("/api/v1/sessions/{$id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', SessionStatus::Completed->value);

        $this->assertNotNull(Session::query()->find($id)->completed_at);
    }

    public function test_cancelling_a_session_does_not_delete_it(): void
    {
        $id = $this->postJson('/api/v1/sessions', $this->payload())->json('data.id');

        $this->deleteJson("/api/v1/sessions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status', SessionStatus::Cancelled->value);

        $this->assertDatabaseHas('sessions', ['id' => $id, 'status' => 'cancelled']);
    }

    public function test_sessions_are_filtered_by_active_and_past(): void
    {
        $draft = $this->postJson('/api/v1/sessions', $this->payload(['name' => 'Draft']))->json('data.id');
        $done = $this->postJson('/api/v1/sessions', $this->payload(['name' => 'Done']))->json('data.id');
        $this->postJson("/api/v1/sessions/{$done}/complete")->assertOk();

        $active = $this->getJson('/api/v1/sessions?status=active')->assertOk();
        $this->assertSame([$draft], array_column($active->json('data'), 'id'));

        $past = $this->getJson('/api/v1/sessions?status=past')->assertOk();
        $this->assertSame([$done], array_column($past->json('data'), 'id'));
    }
}
