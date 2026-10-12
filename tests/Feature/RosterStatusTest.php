<?php

namespace Tests\Feature;

use App\Enums\PlayerGender;
use App\Models\Player;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\User;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Roster statuses (confirmed / waitlisted / requested) and tags (host,
 * no_show), and what each one changes.
 */
class RosterStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->creator = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $this->creator->id, 'display_name' => 'Host', 'initials' => 'HO', 'gender' => PlayerGender::Male,
        ]);
        $others = collect(['Ana', 'Ben', 'Cid', 'Dea'])->map(fn ($n) => Player::query()->create([
            'display_name' => $n, 'initials' => strtoupper(substr($n, 0, 2)),
        ]));

        $this->session = app(SessionBuilder::class)->create(
            [
                'name' => 'Roster', 'match_type_code' => 'americano', 'scheduled_date' => '2026-10-18',
                'court_count' => 1, 'scoring_type' => 'normal', 'points_target' => 24,
            ],
            $others->map(fn ($p) => ['player_id' => $p->id])->all(),
            $hostPlayer->id,
        );

        Sanctum::actingAs($this->creator);
    }

    private function participant(string $name): SessionParticipant
    {
        return $this->session->participants()->where('display_name_snapshot', $name)->firstOrFail();
    }

    private function patchParticipant(string $name, array $body)
    {
        return $this->patchJson("/api/v1/sessions/{$this->session->id}/participants/{$this->participant($name)->id}", $body);
    }

    public function test_the_creator_is_on_the_roster_confirmed_and_tagged_host(): void
    {
        $response = $this->getJson("/api/v1/sessions/{$this->session->id}")->assertOk();

        $host = collect($response->json('data.participants'))->firstWhere('display_name', 'Host');
        $this->assertSame('confirmed', $host['status']);
        $this->assertSame(['host'], $host['tags']);
        $this->assertTrue($host['is_creator']);
        $this->assertTrue($response->json('data.can_manage'));
    }

    public function test_players_added_by_a_host_or_from_reclub_are_confirmed(): void
    {
        $guest = Player::query()->create(['display_name' => 'Eko', 'initials' => 'EK']);
        $imported = Player::query()->create(['display_name' => 'Fey', 'initials' => 'FE']);

        $this->postJson("/api/v1/sessions/{$this->session->id}/participants", ['player_id' => $guest->id])
            ->assertCreated()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/v1/sessions/{$this->session->id}/participants", ['player_id' => $imported->id, 'source' => 'reclub'])
            ->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.source', 'reclub');
    }

    public function test_a_player_asking_to_join_is_requested_until_a_host_decides(): void
    {
        $user = User::factory()->create();
        Player::query()->create(['user_id' => $user->id, 'display_name' => 'Gus', 'initials' => 'GU']);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/sessions/{$this->session->id}/join")
            ->assertCreated()->assertJsonPath('data.status', 'requested');
        $this->postJson("/api/v1/sessions/{$this->session->id}/join")->assertStatus(409);

        // Not a host: cannot approve themselves.
        $this->patchParticipant('Gus', ['status' => 'confirmed'])->assertForbidden();
        $this->assertFalse($this->getJson("/api/v1/sessions/{$this->session->id}")->json('data.can_manage'));

        Sanctum::actingAs($this->creator);
        $this->patchParticipant('Gus', ['status' => 'waitlisted'])->assertOk()->assertJsonPath('data.status', 'waitlisted');
        $this->patchParticipant('Gus', ['status' => 'confirmed'])->assertOk()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_a_host_cannot_set_requested_or_unknown_statuses_or_tags(): void
    {
        $this->patchParticipant('Ana', ['status' => 'requested'])
            ->assertStatus(422)->assertJsonPath('errors.status.0', 'A host can set a player to confirmed or waitlisted.');
        $this->patchParticipant('Ana', ['tags' => ['captain']])->assertStatus(422)->assertJsonValidationErrors(['tags.0']);
    }

    public function test_only_confirmed_players_without_no_show_are_put_into_matches(): void
    {
        // 5 confirmed players on 1 court: one sits out. Make it deterministic:
        // waitlist Ana and mark Ben no-show, leaving exactly 3 eligible.
        $this->patchParticipant('Ana', ['status' => 'waitlisted'])->assertOk();
        $this->patchParticipant('Ben', ['tags' => ['no_show']])->assertOk();

        $this->postJson("/api/v1/sessions/{$this->session->id}/rounds")
            ->assertStatus(409)->assertJsonPath('message', 'At least 4 active players are required.');

        // Ben turns up: four eligible again, and the round uses exactly them.
        $this->patchParticipant('Ben', ['tags' => []])->assertOk();
        $round = $this->postJson("/api/v1/sessions/{$this->session->id}/rounds")->assertCreated()->json('data');

        $names = collect($round['matches'][0]['teams'])->flatMap(fn ($t) => array_column($t['players'], 'display_name'))->sort()->values()->all();
        $this->assertSame(['Ben', 'Cid', 'Dea', 'Host'], $names);
    }

    public function test_hand_entered_matches_explain_why_a_player_cannot_play(): void
    {
        $this->patchParticipant('Ana', ['status' => 'waitlisted'])->assertOk();
        $this->patchParticipant('Ben', ['tags' => ['no_show']])->assertOk();

        $ids = fn (...$names) => array_map(fn ($n) => $this->participant($n)->id, $names);

        $this->postJson("/api/v1/sessions/{$this->session->id}/rounds", ['matches' => [
            ['court' => 1, 'team_a' => $ids('Host', 'Ana'), 'team_b' => $ids('Cid', 'Dea')],
        ]])->assertStatus(422)->assertJsonPath('message', 'Match 1: Ana is waitlisted. Confirm them first.');

        $this->postJson("/api/v1/sessions/{$this->session->id}/rounds", ['matches' => [
            ['court' => 1, 'team_a' => $ids('Host', 'Ben'), 'team_b' => $ids('Cid', 'Dea')],
        ]])->assertStatus(422)->assertJsonPath('message', 'Match 1: Ben is marked no-show.');
    }

    public function test_a_promoted_host_can_manage_and_a_demoted_one_cannot(): void
    {
        $user = User::factory()->create();
        $player = Player::query()->create(['user_id' => $user->id, 'display_name' => 'Ivy', 'initials' => 'IV']);
        app(SessionBuilder::class)->addParticipant($this->session, $player->id);

        $this->patchParticipant('Ivy', ['tags' => ['host']])->assertOk()->assertJsonPath('data.tags', ['host'])->assertJsonPath('data.role', 'host');

        Sanctum::actingAs($user);
        $this->patchParticipant('Ana', ['status' => 'waitlisted'])->assertOk();
        $this->assertTrue($this->getJson("/api/v1/sessions/{$this->session->id}")->json('data.can_manage'));

        Sanctum::actingAs($this->creator);
        $this->patchParticipant('Ivy', ['tags' => []])->assertOk()->assertJsonPath('data.role', 'player');

        Sanctum::actingAs($user);
        $this->patchParticipant('Ana', ['status' => 'confirmed'])->assertForbidden();
    }

    public function test_the_creator_always_stays_host_and_on_the_roster(): void
    {
        $this->patchParticipant('Host', ['tags' => []])
            ->assertStatus(422)->assertJsonPath('message', 'The session creator is always a host.');

        $this->deleteJson("/api/v1/sessions/{$this->session->id}/participants/{$this->participant('Host')->id}")
            ->assertStatus(409);
    }

    public function test_the_session_list_counts_confirmed_players_only(): void
    {
        $this->patchParticipant('Ana', ['status' => 'waitlisted'])->assertOk();

        $row = collect($this->getJson('/api/v1/sessions?status=active')->assertOk()->json('data'))->firstWhere('id', $this->session->id);
        $this->assertSame(4, $row['participant_count']);
    }
}
