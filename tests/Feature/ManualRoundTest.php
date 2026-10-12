<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Enums\PlayerGender;
use App\Enums\RoundStatus;
use App\Enums\SessionStatus;
use App\Enums\TeamSide;
use App\Models\MatchGame;
use App\Models\Player;
use App\Models\Session;
use App\Models\User;
use App\Services\ScoringService;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rounds entered by hand: an inter-club sheet, or a Mexicano round the
 * organiser picks. Covers validation, queued rounds, fixed partnerships
 * and per-match formats.
 */
class ManualRoundTest extends TestCase
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
            'gender' => PlayerGender::Male,
        ]);

        Sanctum::actingAs($this->user);
    }

    /**
     * Host + Ana…Gus = 8 players on 3 courts, tennis total5.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeSession(string $matchType = 'americano', array $overrides = []): Session
    {
        $others = collect(['Ana', 'Ben', 'Cid', 'Dea', 'Edo', 'Fey', 'Gus'])->map(fn ($n) => Player::query()->create([
            'display_name' => $n,
            'initials' => strtoupper(substr($n, 0, 2)),
            'gender' => PlayerGender::Male,
        ]));

        return app(SessionBuilder::class)->create(
            [
                'name' => 'Sparing',
                'match_type_code' => $matchType,
                'scheduled_date' => '2026-10-18',
                'court_count' => 3,
                'scoring_type' => 'tennis',
                'tennis_mode' => 'total5',
                ...$overrides,
            ],
            $others->map(fn ($p) => ['player_id' => $p->id])->all(),
            $this->player->id,
        );
    }

    private function pid(Session $session, string $name): string
    {
        return $session->participants()->where('display_name_snapshot', $name)->value('id');
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string, 3: string, 4: string}>  $rows  court, a1, a2, b1, b2
     * @return array<string, mixed>
     */
    private function roundPayload(Session $session, array $rows): array
    {
        return ['matches' => array_map(fn ($r) => [
            'court' => $r[0],
            'team_a' => [$this->pid($session, $r[1]), $this->pid($session, $r[2])],
            'team_b' => [$this->pid($session, $r[3]), $this->pid($session, $r[4])],
            ...(isset($r[5]) ? ['tennis_mode' => $r[5]] : []),
        ], $rows)];
    }

    public function test_a_round_can_be_entered_match_by_match(): void
    {
        $session = $this->makeSession();

        $response = $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [
            [1, 'Host', 'Ana', 'Ben', 'Cid'],
            [3, 'Dea', 'Edo', 'Fey', 'Gus'],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.round_number', 1)
            ->assertJsonPath('data.status', RoundStatus::InProgress->value)
            ->assertJsonCount(2, 'data.matches');

        $byCourt = collect($response->json('data.matches'))->keyBy('court.court_number');
        $this->assertSame(['Host', 'Ana'], array_column($byCourt[1]['teams'][0]['players'], 'display_name'));
        $this->assertSame(['Fey', 'Gus'], array_column($byCourt[3]['teams'][1]['players'], 'display_name'));

        // Court 2 was left empty on purpose; the first round starts the session.
        $this->assertDatabaseCount('matches', 2);
        $this->assertSame(SessionStatus::Active, $session->refresh()->status);
    }

    public function test_rounds_entered_while_one_is_open_queue_as_pending_and_are_promoted(): void
    {
        $session = $this->makeSession();

        $first = $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[1, 'Host', 'Ana', 'Ben', 'Cid']]))
            ->assertCreated()->json('data');
        $second = $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[1, 'Dea', 'Edo', 'Fey', 'Gus']]))
            ->assertCreated()->json('data');

        $this->assertSame(RoundStatus::Pending->value, $second['status'], 'the second round waits for the first');
        $this->assertSame(2, $second['round_number']);

        $this->postJson("/api/v1/rounds/{$first['id']}/complete")->assertOk();

        $this->assertDatabaseHas('rounds', ['id' => $second['id'], 'status' => RoundStatus::InProgress->value]);
    }

    public function test_an_explicit_round_number_lets_a_schedule_skip_slots_but_cannot_repeat(): void
    {
        $session = $this->makeSession();

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", ['round_number' => 3, ...$this->roundPayload($session, [[1, 'Host', 'Ana', 'Ben', 'Cid']])])
            ->assertCreated()->assertJsonPath('data.round_number', 3);

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", ['round_number' => 3, ...$this->roundPayload($session, [[2, 'Dea', 'Edo', 'Fey', 'Gus']])])
            ->assertStatus(422)->assertJsonPath('message', 'Round 3 already exists.');

        // Without a number the next free one is taken.
        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[2, 'Dea', 'Edo', 'Fey', 'Gus']]))
            ->assertCreated()->assertJsonPath('data.round_number', 4);
    }

    public function test_a_player_cannot_be_listed_twice_in_one_round(): void
    {
        $session = $this->makeSession();

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [
            [1, 'Host', 'Ana', 'Ben', 'Cid'],
            [2, 'Ana', 'Dea', 'Edo', 'Fey'],
        ]))->assertStatus(422)->assertJsonPath('message', 'Match 2: Ana is listed twice in this round.');

        $this->assertDatabaseCount('rounds', 0);
    }

    public function test_unknown_courts_withdrawn_players_and_reused_courts_are_rejected(): void
    {
        $session = $this->makeSession();

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[4, 'Host', 'Ana', 'Ben', 'Cid']]))
            ->assertStatus(422)->assertJsonPath('message', 'Match 1: court 4 does not exist in this session.');

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [
            [1, 'Host', 'Ana', 'Ben', 'Cid'],
            [1, 'Dea', 'Edo', 'Fey', 'Gus'],
        ]))->assertStatus(422)->assertJsonPath('message', 'Match 2: court 1 is used twice in this round.');

        $gus = $this->pid($session, 'Gus');
        $this->deleteJson("/api/v1/sessions/{$session->id}/participants/{$gus}")->assertOk();

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[1, 'Dea', 'Edo', 'Fey', 'Gus']]))
            ->assertStatus(422)->assertJsonPath('message', 'Match 1: Gus has withdrawn from this session.');

        // Shape errors are caught by validation before the builder runs.
        $this->postJson("/api/v1/sessions/{$session->id}/rounds", ['matches' => [['court' => 1, 'team_a' => [$gus], 'team_b' => [$gus, $gus]]]])
            ->assertStatus(422)->assertJsonValidationErrors(['matches.0.team_a']);
    }

    public function test_team_formats_record_partnerships_and_refuse_to_split_them(): void
    {
        $session = $this->makeSession('team_americano');

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [
            [1, 'Host', 'Ana', 'Ben', 'Cid'],
            [2, 'Dea', 'Edo', 'Fey', 'Gus'],
        ]))->assertCreated();

        $this->assertDatabaseCount('session_teams', 4);
        $this->assertSame(
            $session->participants()->where('display_name_snapshot', 'Host')->value('session_team_id'),
            $session->participants()->where('display_name_snapshot', 'Ana')->value('session_team_id'),
        );

        // Ana with Ben next round: Ana's partner is Host.
        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[1, 'Ana', 'Ben', 'Cid', 'Dea']]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ana already partners Host in this session and cannot play with Ben.');

        // The same pairs against new opponents are fine.
        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[1, 'Host', 'Ana', 'Dea', 'Edo']]))
            ->assertCreated();
        $this->assertDatabaseCount('session_teams', 4); // no new team for an existing pair
    }

    public function test_each_match_can_play_to_its_own_format(): void
    {
        $session = $this->makeSession();

        $round = $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [
            [1, 'Host', 'Ana', 'Ben', 'Cid'],            // session rule: total5
            [2, 'Dea', 'Edo', 'Fey', 'Gus', 'total4'],   // override: total4
        ]))->assertCreated()->json('data');

        $byCourt = collect($round['matches'])->keyBy('court.court_number');
        $this->assertNull($byCourt[1]['tennis_mode']);
        $this->assertSame('total4', $byCourt[2]['tennis_mode']);

        $scoring = app(ScoringService::class);

        // 3-1 ends a total4 match…
        $women = MatchGame::query()->findOrFail($byCourt[2]['id']);
        foreach ([TeamSide::A, TeamSide::A, TeamSide::B, TeamSide::A] as $side) {
            for ($p = 0; $p < 4; $p++) {
                $women = $scoring->recordPoint($women, $side);
            }
        }
        $this->assertSame(MatchStatus::Finished, $women->status);
        $this->assertSame([3, 1], [$women->games_a, $women->games_b]);

        // …but not a total5 one.
        $men = MatchGame::query()->findOrFail($byCourt[1]['id']);
        foreach ([TeamSide::A, TeamSide::A, TeamSide::B, TeamSide::A] as $side) {
            for ($p = 0; $p < 4; $p++) {
                $men = $scoring->recordPoint($men, $side);
            }
        }
        $this->assertSame(MatchStatus::Live, $men->status);

        // The sheet reflects both rules.
        $sheet = $this->getJson("/api/v1/sessions/{$session->id}/order-of-play")->assertOk()->json('data');
        $this->assertSame('Total 5 game · Total 4 game (2-2: 1 golden point)', $sheet['session']['format']);
        $this->assertFalse($sheet['rows'][0]['golden_point']);
        $this->assertTrue($sheet['rows'][1]['golden_point']);
    }

    public function test_a_mexicano_round_can_be_hand_picked(): void
    {
        $session = $this->makeSession('mexicano');

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [
            [1, 'Host', 'Gus', 'Ana', 'Fey'],
            [2, 'Ben', 'Edo', 'Cid', 'Dea'],
        ]))->assertCreated()->assertJsonCount(2, 'data.matches');
    }

    public function test_auto_generation_waits_while_a_hand_entered_schedule_is_open(): void
    {
        $session = $this->makeSession();

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[1, 'Host', 'Ana', 'Ben', 'Cid']]))
            ->assertCreated();

        $this->postJson("/api/v1/sessions/{$session->id}/rounds")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Finish the current round before generating another.');
    }

    public function test_only_the_host_or_an_admin_may_enter_rounds(): void
    {
        $session = $this->makeSession();

        $stranger = User::factory()->create();
        Player::query()->create(['user_id' => $stranger->id, 'display_name' => 'Zed', 'initials' => 'ZE']);
        Sanctum::actingAs($stranger);

        $this->postJson("/api/v1/sessions/{$session->id}/rounds", $this->roundPayload($session, [[1, 'Host', 'Ana', 'Ben', 'Cid']]))
            ->assertForbidden();
    }
}
