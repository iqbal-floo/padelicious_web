<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Importing a Reclub meet roster from its public page. Reclub is faked
 * with a small fixture in the page's real structure; no network.
 */
class ReclubImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Player::query()->create(['user_id' => $this->user->id, 'display_name' => 'Host', 'initials' => 'HO']);
        Sanctum::actingAs($this->user);

        Http::preventStrayRequests();
    }

    private function fakeMeet(int $status = 200): void
    {
        Http::fake([
            'reclub.co/m/*' => Http::response($status === 200 ? file_get_contents(base_path('tests/Fixtures/reclub_meet.html')) : '', $status),
        ]);
    }

    public function test_confirmed_and_waitlisted_players_are_imported_with_their_status(): void
    {
        $this->fakeMeet();

        $response = $this->postJson('/api/v1/reclub/import', ['code' => 'p828w9']);

        $response->assertOk()
            ->assertJsonPath('data.code', 'P828W9')
            ->assertJsonPath('data.title', 'Saturday Social Padel')
            ->assertJsonPath('data.url', 'https://reclub.co/m/P828W9')
            ->assertJsonCount(4, 'data.players');

        $rows = collect($response->json('data.players'))->mapWithKeys(fn ($r) => [$r['player']['display_name'] => $r]);

        $this->assertSame('confirmed', $rows['Ana Lee']['status']);
        $this->assertSame('ana-lee-12', $rows['Ana Lee']['player']['reclub_username']);
        // Reclub handles may contain '-'; ours may not, so one is derived.
        $this->assertSame('ana.lee.12', $rows['Ana Lee']['player']['username']);
        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9._]{1,29}$/', $rows['Ana Lee']['player']['username']);
        $this->assertSame('AL', $rows['Ana Lee']['player']['initials']);
        $this->assertSame('https://assets.reclub.co/user-avatars/1.webp', $rows['Ana Lee']['player']['avatar_url']);
        $this->assertTrue($rows['Ana Lee']['player']['is_guest']);

        // Listed on both Confirmed and Waitlisted: counted once, confirmed.
        $this->assertSame('confirmed', $rows['Ben']['status']);
        $this->assertSame('waitlisted', $rows['Dea']['status']);
        // No profile link: still imported, without a Reclub handle.
        $this->assertNull($rows['Cid']['player']['reclub_username']);

        $this->assertDatabaseHas('reclub_imports', ['match_code' => 'P828W9', 'status' => 'success', 'imported_count' => 4]);
        Http::assertSent(fn ($request) => $request->url() === 'https://reclub.co/m/P828W9');
    }

    public function test_importing_again_reuses_players_by_reclub_handle(): void
    {
        $this->fakeMeet();

        $first = $this->postJson('/api/v1/reclub/import', ['code' => 'P828W9'])->json('data.players');
        $count = Player::query()->count();
        $second = $this->postJson('/api/v1/reclub/import', ['code' => 'P828W9'])->json('data.players');

        $idOf = fn ($rows, $name) => collect($rows)->firstWhere('player.display_name', $name)['player']['id'];
        $this->assertSame($idOf($first, 'Ana Lee'), $idOf($second, 'Ana Lee'));
        // Only Cid (no handle) is created again.
        $this->assertSame($count + 1, Player::query()->count());
    }

    public function test_an_unknown_code_and_a_malformed_code_are_reported(): void
    {
        $this->fakeMeet(404);

        $this->postJson('/api/v1/reclub/import', ['code' => 'NOPE99'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No Reclub meet was found for code NOPE99.');
        $this->assertDatabaseHas('reclub_imports', ['match_code' => 'NOPE99', 'status' => 'failed']);

        $this->postJson('/api/v1/reclub/import', ['code' => 'bad code!'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That does not look like a Reclub meet code.');
    }

    public function test_imported_players_join_a_session_with_their_reclub_status(): void
    {
        $this->fakeMeet();
        $rows = $this->postJson('/api/v1/reclub/import', ['code' => 'P828W9'])->json('data.players');

        $session = $this->postJson('/api/v1/sessions', [
            'name' => 'From Reclub',
            'match_type_code' => 'americano',
            'scheduled_date' => '2026-10-18',
            'court_count' => 1,
            'scoring_type' => 'normal',
            'points_target' => 24,
            'participants' => array_map(fn ($r) => [
                'player_id' => $r['player']['id'],
                'source' => 'reclub',
                'status' => $r['status'],
            ], $rows),
        ])->assertCreated()->json('data');

        $byName = collect($session['participants'])->keyBy('display_name');
        $this->assertSame('confirmed', $byName['Ana Lee']['status']);
        $this->assertSame('reclub', $byName['Ana Lee']['source']);
        $this->assertSame('waitlisted', $byName['Dea']['status']);
        $this->assertSame('confirmed', $byName['Host']['status'], 'the creator is always confirmed');
    }
}
