<?php

namespace Tests\Feature;

use App\Enums\PlayerGender;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_gender_can_be_set_at_registration(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Dina Putri',
            'email' => 'dina@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'gender' => 'female',
        ])
            ->assertCreated()
            ->assertJsonPath('user.player.gender', 'female');
    }

    public function test_gender_defaults_to_undisclosed_when_omitted(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'full_name' => 'No Gender',
            'email' => 'none@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])
            ->assertCreated()
            ->assertJsonPath('user.player.gender', 'undisclosed');
    }

    public function test_an_invalid_gender_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'full_name' => 'Bad Gender',
            'email' => 'bad@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'gender' => 'yes',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('gender');
    }

    public function test_an_existing_user_can_set_their_gender(): void
    {
        $user = User::factory()->create();
        Player::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Iqbal',
            'initials' => 'IQ',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', ['gender' => 'male'])
            ->assertOk()
            ->assertJsonPath('player.gender', 'male');

        $this->assertSame(
            PlayerGender::Male,
            $user->refresh()->player->gender
        );
    }

    public function test_the_profile_endpoint_updates_other_fields_too(): void
    {
        $user = User::factory()->create(['full_name' => 'Old Name']);
        Player::query()->create([
            'user_id' => $user->id, 'display_name' => 'Old', 'initials' => 'OL',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', [
            'full_name' => 'New Name',
            'display_name' => 'Newbie',
            'skill_level' => 3.5,
            'avatar_color_index' => 5,
        ])
            ->assertOk()
            ->assertJsonPath('full_name', 'New Name')
            ->assertJsonPath('player.display_name', 'Newbie')
            ->assertJsonPath('player.avatar_color_index', 5);
    }

    public function test_skill_level_is_bounded(): void
    {
        $user = User::factory()->create();
        Player::query()->create([
            'user_id' => $user->id, 'display_name' => 'X', 'initials' => 'XX',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', ['skill_level' => 9])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('skill_level');
    }

    public function test_avatar_colour_index_is_bounded(): void
    {
        $user = User::factory()->create();
        Player::query()->create([
            'user_id' => $user->id, 'display_name' => 'X', 'initials' => 'XX',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', ['avatar_color_index' => 8])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar_color_index');
    }

    public function test_the_profile_endpoint_requires_authentication(): void
    {
        $this->patchJson('/api/v1/profile', ['gender' => 'male'])
            ->assertUnauthorized();
    }

    public function test_a_user_without_a_player_profile_gets_one_created(): void
    {
        // Older accounts predate automatic profile creation.
        $user = User::factory()->create(['full_name' => 'Legacy User']);
        $this->assertNull($user->player);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/profile', ['gender' => 'female'])
            ->assertOk()
            ->assertJsonPath('player.gender', 'female')
            ->assertJsonPath('player.display_name', 'Legacy');
    }
}
