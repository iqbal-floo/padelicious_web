<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Guest players added by hand, and the username as the unique handle.
 */
class PlayerApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Player::query()->create(['user_id' => $this->user->id, 'display_name' => 'Host', 'initials' => 'HO']);

        Sanctum::actingAs($this->user);
    }

    public function test_a_guest_player_can_be_added_by_hand(): void
    {
        $response = $this->postJson('/api/v1/players', [
            'display_name' => 'Hardian',
            'username' => 'Hardian.P',
            'gender' => 'male',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.display_name', 'Hardian')
            ->assertJsonPath('data.username', 'hardian.p', 'usernames are stored lowercase')
            ->assertJsonPath('data.initials', 'HA')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.is_guest', true);

        $this->assertDatabaseHas('players', ['username' => 'hardian.p', 'user_id' => null]);
    }

    public function test_two_players_may_share_a_name_but_not_a_username(): void
    {
        $this->postJson('/api/v1/players', ['display_name' => 'Iqbal', 'username' => 'iqbal.a'])->assertCreated();
        $this->postJson('/api/v1/players', ['display_name' => 'Iqbal', 'username' => 'iqbal.b'])->assertCreated();

        $this->assertSame(2, Player::query()->where('display_name', 'Iqbal')->count());

        // Case-insensitive: citext.
        $this->postJson('/api/v1/players', ['display_name' => 'Someone', 'username' => 'IQBAL.A'])
            ->assertStatus(422)
            ->assertJsonPath('errors.username.0', 'This username is already taken.');
    }

    public function test_usernames_are_validated(): void
    {
        $this->postJson('/api/v1/players', ['display_name' => 'X', 'username' => 'has space'])
            ->assertStatus(422)->assertJsonValidationErrors(['username']);

        $this->postJson('/api/v1/players', ['display_name' => 'X', 'username' => 'a'])
            ->assertStatus(422)->assertJsonValidationErrors(['username']);

        $this->postJson('/api/v1/players', ['display_name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors(['username']);
    }

    public function test_players_created_without_a_username_get_one_from_their_name(): void
    {
        $first = Player::query()->create(['display_name' => 'Rina Hartono', 'initials' => 'RH']);
        $second = Player::query()->create(['display_name' => 'Rina Hartono', 'initials' => 'RH']);

        $this->assertSame('rina.hartono', $first->username);
        $this->assertSame('rina.hartono2', $second->username);
    }

    public function test_search_matches_the_username_too(): void
    {
        Player::query()->create(['display_name' => 'Iqbal', 'username' => 'iqbal.floo', 'initials' => 'IQ']);
        Player::query()->create(['display_name' => 'Iqbal', 'username' => 'iqbal.permana', 'initials' => 'IQ']);

        $response = $this->getJson('/api/v1/players?search=permana');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'iqbal.permana');
    }

    public function test_adding_a_player_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/players', ['display_name' => 'X', 'username' => 'xx'], ['Authorization' => ''])
            ->assertStatus(401);
    }
}
