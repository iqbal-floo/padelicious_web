<?php

namespace Tests\Feature;

use App\Enums\TeamSide;
use App\Models\MatchGame;
use App\Models\Player;
use App\Models\User;
use App\Services\ScoringService;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveScoreApiTest extends TestCase
{
    use RefreshDatabase;

    private string $matchId;

    protected function setUp(): void
    {
        parent::setUp();

        $host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $host->id, 'display_name' => 'Iqbal', 'initials' => 'IQ',
        ]);
        $others = collect(['Rafi', 'Budi', 'Hendra'])->map(
            fn ($n) => Player::query()->create([
                'display_name' => $n, 'initials' => substr($n, 0, 2),
            ])
        );

        $builder = app(SessionBuilder::class);
        $session = $builder->create(
            [
                'name' => 'Friday Night', 'match_type_code' => 'americano',
                'scheduled_date' => now()->toDateString(), 'court_count' => 1,
                'scoring_type' => 'normal', 'points_target' => 24,
            ],
            $others->map(fn ($p) => ['player_id' => $p->id])->all(),
            $hostPlayer->id,
        );

        $this->matchId = $builder->generateRound($session)->matches->first()->id;
    }

    public function test_the_live_snapshot_is_public(): void
    {
        // No Sanctum token: a spectator only has the match uuid.
        $this->getJson("/api/v1/live/matches/{$this->matchId}")
            ->assertOk()
            ->assertJsonPath('data.match_id', $this->matchId)
            ->assertJsonPath('data.session.name', 'Friday Night')
            ->assertJsonPath('data.games.a', 0)
            ->assertJsonPath('data.points.a', '0')
            ->assertJsonCount(2, 'data.teams')
            ->assertJsonCount(2, 'data.teams.0.players');
    }

    public function test_the_snapshot_reflects_a_scored_point(): void
    {
        $match = MatchGame::query()->findOrFail($this->matchId);
        app(ScoringService::class)->recordPoint($match, TeamSide::A);

        $this->getJson("/api/v1/live/matches/{$this->matchId}")
            ->assertOk()
            ->assertJsonPath('data.points.a', '15')
            ->assertJsonPath('data.status', 'live');
    }

    public function test_an_unknown_match_returns_404(): void
    {
        $this->getJson('/api/v1/live/matches/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_the_spectator_page_renders_for_a_real_match(): void
    {
        $this->get("/live/{$this->matchId}")
            ->assertOk()
            ->assertSee($this->matchId, false)
            ->assertSee('Live Score', false);
    }

    public function test_the_spectator_page_404s_for_an_unknown_match(): void
    {
        $this->get('/live/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    public function test_the_snapshot_never_exposes_a_way_to_change_the_score(): void
    {
        // The public prefix is read-only: writing must still require auth.
        $this->postJson("/api/v1/matches/{$this->matchId}/points", ['team' => 'a'])
            ->assertUnauthorized();
    }
}
