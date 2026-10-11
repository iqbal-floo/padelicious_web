<?php

namespace Tests\Feature;

use App\Enums\PlayerGender;
use App\Models\Player;
use App\Models\Round;
use App\Models\Session;
use App\Models\SessionTeam;
use App\Models\User;
use App\Services\SessionBuilder;
use App\Services\StandingsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

class TeamFormatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $names
     * @return Collection<int, Player>
     */
    private function players(array $names, ?PlayerGender $gender = null): Collection
    {
        return collect($names)->map(fn ($n) => Player::query()->create([
            'display_name' => $n,
            'initials' => strtoupper(substr($n, 0, 2)),
            'gender' => $gender ?? PlayerGender::Undisclosed,
        ]));
    }

    private function makeSession(string $matchType, Collection $roster, int $courts = 1): Session
    {
        $host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $host->id,
            'display_name' => 'Host',
            'initials' => 'HO',
            'gender' => PlayerGender::Male,
        ]);

        return app(SessionBuilder::class)->create(
            [
                'name' => "Test {$matchType}",
                'match_type_code' => $matchType,
                'scheduled_date' => now()->toDateString(),
                'court_count' => $courts,
                'scoring_type' => 'normal',
                'points_target' => 24,
            ],
            $roster->map(fn ($p) => ['player_id' => $p->id])->all(),
            $hostPlayer->id,
        );
    }

    /**
     * Partnerships as sets of participant ids, per match.
     *
     * @return list<list<array<int, string>>>
     */
    private function pairsOf(Round $round): array
    {
        return $round->load('matches.teams.teamPlayers')->matches
            ->map(fn ($m) => $m->teams
                ->sortBy(fn ($t) => $t->side->value)
                ->map(fn ($t) => $t->teamPlayers
                    ->pluck('participant_id')
                    ->sort()
                    ->values()
                    ->all())
                ->values()
                ->all())
            ->all();
    }

    // ---- team_americano: fixed pairs ---------------------------------------

    public function test_team_americano_creates_fixed_teams_on_the_first_round(): void
    {
        // Host + 7 = 8 players = 4 teams.
        $session = $this->makeSession('team_americano', $this->players(['A', 'B', 'C', 'D', 'E', 'F', 'G']), courts: 2);

        $builder = app(SessionBuilder::class);
        $builder->generateRound($session);

        $this->assertDatabaseCount('session_teams', 4);

        // Every active participant is on a team.
        $this->assertSame(
            0,
            $session->participants()->whereNull('session_team_id')->count(),
            'all 8 players should be assigned to a team'
        );

        $teams = SessionTeam::query()->where('session_id', $session->id)->get();
        foreach ($teams as $team) {
            $this->assertCount(2, $team->participants, 'each team has exactly 2 players');
        }
    }

    public function test_team_americano_keeps_the_same_partners_across_rounds(): void
    {
        $session = $this->makeSession('team_americano', $this->players(['A', 'B', 'C', 'D', 'E', 'F', 'G']), courts: 2);
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);
        $firstPartnerships = collect($this->pairsOf($first))->flatten(1)->map(fn ($p) => implode('|', $p))->sort()->values()->all();

        // Close the round so another can be generated.
        $first->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $second = $builder->generateRound($session->refresh());
        $secondPartnerships = collect($this->pairsOf($second))->flatten(1)->map(fn ($p) => implode('|', $p))->sort()->values()->all();

        // The partnerships themselves must be identical — only opponents rotate.
        $this->assertSame(
            $firstPartnerships,
            $secondPartnerships,
            'team_americano must reuse the pairs fixed in round 1'
        );
    }

    public function test_team_americano_partners_survive_three_rounds(): void
    {
        $session = $this->makeSession('team_americano', $this->players(['A', 'B', 'C', 'D', 'E', 'F', 'G']), courts: 2);
        $builder = app(SessionBuilder::class);

        $baseline = null;

        for ($i = 0; $i < 3; $i++) {
            $round = $builder->generateRound($session->refresh());
            $pairs = collect($this->pairsOf($round))->flatten(1)->map(fn ($p) => implode('|', $p))->sort()->values()->all();

            $baseline ??= $pairs;
            $this->assertSame($baseline, $pairs, "round {$i} changed the partnerships");

            $round->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        }

        // Teams were created once, not re-created each round.
        $this->assertDatabaseCount('session_teams', 4);
    }

    public function test_an_individual_format_does_rotate_partners(): void
    {
        // Contrast: americano should NOT keep the same pairs.
        $session = $this->makeSession('americano', $this->players(['A', 'B', 'C', 'D', 'E', 'F', 'G']), courts: 2);
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);
        app(StandingsCalculator::class)->recalculate($session);
        $first->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $second = $builder->generateRound($session->refresh());

        // No session_teams rows for an individual format.
        $this->assertDatabaseCount('session_teams', 0);
        $this->assertNotNull($second);
    }

    public function test_a_team_format_needs_two_complete_teams(): void
    {
        // Host + 4 = 5 players: that is 2 full pairs plus a leftover, so
        // the generic 4-player guard passes and the team rule is reached.
        // With only 3 players it would trip the earlier guard instead.
        $session = $this->makeSession('team_americano', $this->players(['A', 'B', 'C', 'D']));

        $round = app(SessionBuilder::class)->generateRound($session);

        // 2 teams formed, the 5th player sits out.
        $this->assertDatabaseCount('session_teams', 2);
        $this->assertCount(1, $round->matches);
        $this->assertSame(
            1,
            $session->participants()->whereNull('session_team_id')->count(),
            'the odd player out stays unassigned'
        );
    }

    public function test_a_team_format_with_only_one_full_pair_is_rejected(): void
    {
        // Host + 3 = 4 players, but one is left unpaired after the first
        // pair forms, leaving a single team with no opponent.
        $session = $this->makeSession('team_americano', $this->players(['A', 'B', 'C']));

        $builder = app(SessionBuilder::class);

        // 4 players -> 2 complete pairs, which is valid. Withdraw one so
        // only a single full pair can form.
        $odd = $session->participants()->latest('joined_at')->first();
        $odd->forceFill(['status' => 'withdrawn'])->save();

        $this->expectException(RuntimeException::class);
        // 3 active players trips the generic guard first.
        $this->expectExceptionMessage('At least 4 active players are required.');

        $builder->generateRound($session->refresh());
    }

    // ---- mix_americano: gender constraint ----------------------------------

    public function test_mix_americano_pairs_one_man_with_one_woman(): void
    {
        $men = $this->players(['Bob', 'Carl'], PlayerGender::Male);
        $women = $this->players(['Dina', 'Eva', 'Fay'], PlayerGender::Female);

        // Host is male, so 3 men + 3 women; one of each sits out on 1 court.
        $session = $this->makeSession('mix_americano', $men->concat($women));

        $round = app(SessionBuilder::class)->generateRound($session);

        $round->load('matches.teams.teamPlayers.participant.player');

        foreach ($round->matches as $match) {
            foreach ($match->teams as $team) {
                $genders = $team->teamPlayers
                    ->map(fn ($tp) => $tp->participant->player->gender)
                    ->all();

                $this->assertCount(2, $genders);
                $this->assertContains(PlayerGender::Male, $genders, 'each pair needs a man');
                $this->assertContains(PlayerGender::Female, $genders, 'each pair needs a woman');
            }
        }
    }

    public function test_mix_americano_rejects_players_without_a_gender(): void
    {
        // Host (male) + 3 undisclosed.
        $session = $this->makeSession('mix_americano', $this->players(['A', 'B', 'C']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('gender set');

        app(SessionBuilder::class)->generateRound($session);
    }

    public function test_mix_americano_needs_two_of_each_gender(): void
    {
        // Host (male) + 1 man + 2 women = 2 men, 2 women: exactly enough.
        $ok = $this->makeSession(
            'mix_americano',
            $this->players(['Bob'], PlayerGender::Male)
                ->concat($this->players(['Dina', 'Eva'], PlayerGender::Female))
        );

        $round = app(SessionBuilder::class)->generateRound($ok);
        $this->assertCount(1, $round->matches);
    }

    public function test_mix_americano_fails_with_too_few_of_one_gender(): void
    {
        // Host (male) + 3 women = 1 man, 3 women: cannot fill a court.
        $session = $this->makeSession(
            'mix_americano',
            $this->players(['Dina', 'Eva', 'Fay'], PlayerGender::Female)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('at least 2 men and 2 women');

        app(SessionBuilder::class)->generateRound($session);
    }

    // ---- club formats removed ----------------------------------------------

    public function test_the_club_match_types_no_longer_exist(): void
    {
        foreach (['club_americano', 'club_mexicano', 'club_team_americano'] as $code) {
            $this->assertDatabaseMissing('match_types', ['code' => $code]);
        }

        $this->assertDatabaseCount('match_types', 8);
    }

    public function test_creating_a_session_with_a_club_type_is_rejected(): void
    {
        $user = User::factory()->create();
        Player::query()->create([
            'user_id' => $user->id, 'display_name' => 'Host', 'initials' => 'HO',
        ]);

        $this->actingAs($user)->postJson('/api/v1/sessions', [
            'name' => 'Club night',
            'match_type_code' => 'club_americano',
            'scheduled_date' => now()->toDateString(),
            'court_count' => 1,
            'scoring_type' => 'normal',
            'points_target' => 24,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('match_type_code');
    }
}
