<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Enums\PlayerGender;
use App\Enums\TeamSide;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderOfPlayTest extends TestCase
{
    use RefreshDatabase;

    private User $host;

    /**
     * Host + 7 players on 2 courts: one round = 2 matches.
     */
    private function makeSession(PlayerGender $gender = PlayerGender::Male, string $tennisMode = 'total4'): Session
    {
        $this->host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $this->host->id, 'display_name' => 'Host', 'initials' => 'HO', 'gender' => $gender,
        ]);

        $others = Collection::make(['Ana', 'Ben', 'Cid', 'Dea', 'Edo', 'Fey', 'Gus'])
            ->map(fn ($n) => Player::query()->create([
                'display_name' => $n, 'initials' => strtoupper(substr($n, 0, 2)), 'gender' => $gender,
            ]));

        return app(SessionBuilder::class)->create(
            [
                'name' => 'Sparing Test',
                'match_type_code' => 'americano',
                'scheduled_date' => '2026-10-18',
                'court_count' => 2,
                'scoring_type' => 'tennis',
                'tennis_mode' => $tennisMode,
            ],
            $others->map(fn ($p) => ['player_id' => $p->id])->all(),
            $hostPlayer->id,
        );
    }

    public function test_html_sheet_lists_every_match_with_its_players(): void
    {
        $session = $this->makeSession();
        app(SessionBuilder::class)->generateRound($session);

        $response = $this->get(route('sessions.order-of-play', $session));

        $response->assertOk();
        $response->assertSee('Order of Play: Sparing Test');
        $response->assertSee('Total 4 game');
        foreach (['Host', 'Ana', 'Ben', 'Cid', 'Dea', 'Edo', 'Fey', 'Gus'] as $name) {
            $response->assertSee($name);
        }
        // Two matches, courts 1 and 2.
        $response->assertSee('C1');
        $response->assertSee('C2');
    }

    public function test_pdf_download_returns_a_pdf_document(): void
    {
        $session = $this->makeSession();
        app(SessionBuilder::class)->generateRound($session);

        $response = $this->get(route('sessions.order-of-play', [$session, 'format' => 'pdf']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('order-of-play-sparing-test.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_api_rows_carry_category_scores_and_golden_point_flags(): void
    {
        $session = $this->makeSession(PlayerGender::Female);
        $round = app(SessionBuilder::class)->generateRound($session);

        // Finish the court-1 match level, decided by the golden point.
        $round->matches->sortBy('court.court_number')->first()->forceFill([
            'status' => MatchStatus::Finished,
            'games_a' => 2,
            'games_b' => 2,
            'winner_team' => TeamSide::B,
            'finished_at' => now(),
        ])->save();

        Sanctum::actingAs($this->host);
        $response = $this->getJson(route('api.sessions.order-of-play', [$session, 'start' => '08:15', 'slot' => 15]));

        $response->assertOk();
        $response->assertJsonCount(2, 'data.rows');
        $response->assertJsonPath('data.session.format', 'Total 4 game (2-2: 1 golden point)');
        $response->assertJsonPath('data.rows.0.category', 'W');
        $response->assertJsonPath('data.rows.0.time', '08:15');
        $response->assertJsonPath('data.rows.0.court', 1);
        $response->assertJsonPath('data.rows.0.games_a', 2);
        $response->assertJsonPath('data.rows.0.games_b', 2);
        $response->assertJsonPath('data.rows.0.golden_point', true);
        $response->assertJsonPath('data.rows.0.decided_by_golden_point', true);
        $response->assertJsonPath('data.rows.0.winner', 'b');
        // The unplayed match prints blank score boxes.
        $response->assertJsonPath('data.rows.1.games_a', null);
        $response->assertJsonPath('data.rows.1.decided_by_golden_point', false);

        $this->assertStringContainsString('format=pdf', $response->json('data.pdf_url'));
        $this->assertStringContainsString('start=08%3A15', $response->json('data.pdf_url'));
    }

    public function test_an_odd_total_has_no_golden_point_column_values(): void
    {
        $session = $this->makeSession(PlayerGender::Male, 'total5');
        app(SessionBuilder::class)->generateRound($session);

        Sanctum::actingAs($this->host);
        $response = $this->getJson(route('api.sessions.order-of-play', $session));

        $response->assertOk();
        $response->assertJsonPath('data.rows.0.golden_point', false);
        $response->assertJsonPath('data.rows.0.category', 'M');
    }

    public function test_session_resource_links_to_the_sheet(): void
    {
        $session = $this->makeSession();

        Sanctum::actingAs($this->host);
        $response = $this->getJson(route('api.sessions.show', $session));

        $response->assertOk();
        $this->assertStringEndsWith("/sessions/{$session->id}/order-of-play", $response->json('data.order_of_play_url'));
    }

    public function test_a_malformed_start_time_is_rejected(): void
    {
        $session = $this->makeSession();

        $this->getJson(route('sessions.order-of-play', [$session, 'start' => '8am']))
            ->assertStatus(422);
    }

    public function test_the_api_sheet_requires_authentication(): void
    {
        $session = $this->makeSession();

        $this->getJson(route('api.sessions.order-of-play', $session))->assertStatus(401);
    }
}
