<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Iqbal Ramadhan',
            'email' => 'new.user@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'flutter-test',
        ], $overrides);
    }

    public function test_registering_creates_a_user_a_player_and_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('user.email', 'new.user@example.com')
            ->assertJsonPath('user.full_name', 'Iqbal Ramadhan')
            ->assertJsonPath('user.status', 'active')
            // A player profile is created in the same transaction.
            ->assertJsonPath('user.player.display_name', 'Iqbal')
            ->assertJsonPath('user.player.initials', 'IR');

        $this->assertNotEmpty($response->json('token'));

        $user = User::query()->where('email', 'new.user@example.com')->firstOrFail();
        $this->assertNotNull($user->accepted_terms_at);
        $this->assertDatabaseHas('players', ['user_id' => $user->id]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_the_returned_token_authenticates_immediately(): void
    {
        $token = $this->postJson('/api/v1/auth/register', $this->payload())->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('email', 'new.user@example.com');
    }

    public function test_the_password_is_hashed_not_stored_raw(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $user = User::query()->where('email', 'new.user@example.com')->firstOrFail();

        $this->assertNotSame('secret123', $user->password_hash);
        $this->assertTrue(password_verify('secret123', $user->password_hash));
    }

    public function test_the_password_hash_is_never_serialised(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->payload());

        $this->assertStringNotContainsString('password_hash', $response->content());
        $this->assertStringNotContainsString('secret123', $response->content());
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_email_uniqueness_is_case_insensitive(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        // users.email is citext, so this must also be refused.
        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'NEW.USER@EXAMPLE.COM',
        ]))->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_mismatched_confirmation_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password_confirmation' => 'different',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_a_short_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'password' => 'abc12',
            'password_confirmation' => 'abc12',
        ]))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_an_invalid_email_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload(['email' => 'not-an-email']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_required_fields_are_enforced(): void
    {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name', 'email', 'password']);
    }

    public function test_a_single_word_name_still_yields_two_initials(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload([
            'full_name' => 'Iqbal',
            'email' => 'single@example.com',
        ]))
            ->assertCreated()
            ->assertJsonPath('user.player.display_name', 'Iqbal')
            ->assertJsonPath('user.player.initials', 'IQ');
    }

    public function test_the_new_user_can_log_in_with_their_password(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'new.user@example.com',
            'password' => 'secret123',
        ])
            ->assertOk()
            ->assertJsonPath('user.email', 'new.user@example.com')
            // Login now returns the player profile too.
            ->assertJsonPath('user.player.display_name', 'Iqbal');
    }

    public function test_registration_is_rate_limited(): void
    {
        // 6 per minute, then 429.
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/v1/auth/register', $this->payload([
                'email' => "user{$i}@example.com",
            ]))->assertCreated();
        }

        $this->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'seventh@example.com',
        ]))->assertStatus(429);
    }

    public function test_avatar_colour_index_is_within_the_palette(): void
    {
        $this->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        $index = Player::query()->firstOrFail()->avatar_color_index;

        // The CHECK constraint allows 0-7 (AppColors.chipPairs*).
        $this->assertGreaterThanOrEqual(0, $index);
        $this->assertLessThanOrEqual(7, $index);
    }

    public function test_google_sign_in_reports_unconfigured_rather_than_failing(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/v1/auth/google', ['access_token' => 'x'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Google sign-in is not configured on this server.');
    }

    public function test_google_sign_in_requires_an_access_token(): void
    {
        config(['services.google.client_id' => 'test-client-id']);

        $this->postJson('/api/v1/auth/google', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('access_token');
    }
}
