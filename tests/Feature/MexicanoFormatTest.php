<?php

namespace Tests\Feature;

use App\Enums\PlayerGender;
use App\Models\Player;
use App\Models\Round;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\SessionStanding;
use App\Models\User;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MexicanoFormatTest extends TestCase
{
    use RefreshDatabase;

    /**
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

    private function makeSession(string $type, Collection $roster, int $courts = 1, ?PlayerGender $hostGender = null): Session
    {
        $host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $host->id,
            'display_name' => 'Host',
            'initials' => 'HO',
            'gender' => $hostGender ?? PlayerGender::Undisclosed,
        ]);

        return app(SessionBuilder::class)->create(
            [
                'name' => "Test {$type}",
                'match_type_code' => $type,
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
     * Give each named participant an explicit rank and points, so the
     * pairing rule can be asserted against a known order.
     *
     * @param  array<string, int>  $ranks  display name => rank
     */
    private function setRanks(Session $session, array $ranks): void
    {
        foreach ($ranks as $name => $rank) {
            $participant = SessionParticipant::query()
                ->where('session_id', $session->id)
                ->where('display_name_snapshot', $name)
                ->firstOrFail();

            SessionStanding::query()->updateOrCreate(
                ['session_id' => $session->id, 'participant_id' => $participant->id],
                [
                    'rank' => $rank,
                    // Points descend with rank so tie-breaks agree.
                    'points' => 100 - $rank,
                    'matches_played' => 1,
                    'updated_at' => now(),
                ],
            );
        }
    }

    /**
     * @return list<list<list<string>>> per match: [sideA names, sideB names]
     */
    private function pairsOf(Round $round): array
    {
        return $round->load('matches.court', 'matches.teams.teamPlayers.participant')
            ->matches
            ->sortBy(fn ($m) => $m->court->court_number)
            ->map(fn ($m) => $m->teams
                ->sortBy(fn ($t) => $t->side->value)
                ->map(fn ($t) => $t->teamPlayers
                    ->map(fn ($tp) => $tp->participant->display_name_snapshot)
                    ->sort()
                    ->values()
                    ->all())
                ->values()
                ->all())
            ->values()
            ->all();
    }

    // ---- mexicano ----------------------------------------------------------

    public function test_mexicano_pairs_first_with_fourth_against_second_and_third(): void
    {
        // Host + 3 = 4 players on one court.
        $session = $this->makeSession('mexicano', $this->players(['Bee', 'Cee', 'Dee']));

        // Rank them explicitly: Host=1, Bee=2, Cee=3, Dee=4.
        $this->setRanks($session, ['Host' => 1, 'Bee' => 2, 'Cee' => 3, 'Dee' => 4]);

        $round = app(SessionBuilder::class)->generateRound($session->refresh());
        $pairs = $this->pairsOf($round);

        $this->assertCount(1, $pairs);

        // 1st + 4th  vs  2nd + 3rd.
        $sides = collect($pairs[0])->map(fn ($p) => implode('+', $p))->sort()->values()->all();

        $this->assertSame(['Bee+Cee', 'Dee+Host'], $sides);
    }

    public function test_mexicano_seeds_the_top_four_onto_the_first_court(): void
    {
        // Host + 7 = 8 players over 2 courts.
        $session = $this->makeSession(
            'mexicano',
            $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']),
            courts: 2,
        );

        $this->setRanks($session, [
            'Host' => 1, 'P2' => 2, 'P3' => 3, 'P4' => 4,
            'P5' => 5, 'P6' => 6, 'P7' => 7, 'P8' => 8,
        ]);

        $round = app(SessionBuilder::class)->generateRound($session->refresh());
        $pairs = $this->pairsOf($round);

        $this->assertCount(2, $pairs);

        // Court 1 holds ranks 1-4: 1+4 vs 2+3.
        $court1 = collect($pairs[0])->map(fn ($p) => implode('+', $p))->sort()->values()->all();
        $this->assertSame(['Host+P4', 'P2+P3'], $court1);

        // Court 2 holds ranks 5-8: 5+8 vs 6+7.
        $court2 = collect($pairs[1])->map(fn ($p) => implode('+', $p))->sort()->values()->all();
        $this->assertSame(['P5+P8', 'P6+P7'], $court2);
    }

    public function test_mexicano_reorders_when_the_ranking_changes(): void
    {
        $session = $this->makeSession('mexicano', $this->players(['Bee', 'Cee', 'Dee']));
        $builder = app(SessionBuilder::class);

        $this->setRanks($session, ['Host' => 1, 'Bee' => 2, 'Cee' => 3, 'Dee' => 4]);
        $first = $builder->generateRound($session->refresh());
        $firstSides = collect($this->pairsOf($first)[0])
            ->map(fn ($p) => implode('+', $p))->sort()->values()->all();
        $this->assertSame(['Bee+Cee', 'Dee+Host'], $firstSides);

        $first->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        // Dee climbs to 1st, Host drops to 4th.
        $this->setRanks($session, ['Dee' => 1, 'Bee' => 2, 'Cee' => 3, 'Host' => 4]);

        $second = $builder->generateRound($session->refresh());
        $secondSides = collect($this->pairsOf($second)[0])
            ->map(fn ($p) => implode('+', $p))->sort()->values()->all();

        // Same four names, but 1+4 is now Dee+Host — the standings drove it.
        $this->assertSame(['Bee+Cee', 'Dee+Host'], $secondSides);

        // Sanity: the pairing genuinely follows rank, so swapping ranks 2
        // and 4 must change who partners whom.
        $second->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->setRanks($session, ['Dee' => 1, 'Host' => 2, 'Cee' => 3, 'Bee' => 4]);

        $third = $builder->generateRound($session->refresh());
        $thirdSides = collect($this->pairsOf($third)[0])
            ->map(fn ($p) => implode('+', $p))->sort()->values()->all();

        $this->assertSame(['Bee+Dee', 'Cee+Host'], $thirdSides);
        $this->assertNotSame($firstSides, $thirdSides);
    }

    public function test_mexicano_falls_back_to_roster_order_in_round_one(): void
    {
        // No standings yet: unranked players must still produce a valid round.
        $session = $this->makeSession('mexicano', $this->players(['Bee', 'Cee', 'Dee']));

        $round = app(SessionBuilder::class)->generateRound($session);

        $this->assertCount(1, $round->matches);
        $this->assertCount(2, $round->matches->first()->teams);
    }

    public function test_americano_does_not_use_the_ranking_rule(): void
    {
        // Same ranks, americano: pairs [1,3] v [2,4], not [1,4] v [2,3].
        $session = $this->makeSession('americano', $this->players(['Bee', 'Cee', 'Dee']));
        $this->setRanks($session, ['Host' => 1, 'Bee' => 2, 'Cee' => 3, 'Dee' => 4]);

        $round = app(SessionBuilder::class)->generateRound($session->refresh());
        $sides = collect($this->pairsOf($round)[0])
            ->map(fn ($p) => implode('+', $p))->sort()->values()->all();

        // Americano sorts by matches_played (all equal here) so roster order
        // holds: Host+Cee vs Bee+Dee. The point is it is NOT the 1+4 split.
        $this->assertNotSame(['Bee+Cee', 'Dee+Host'], $sides);
    }

    // ---- team_mexicano -----------------------------------------------------

    public function test_team_mexicano_keeps_fixed_pairs(): void
    {
        $session = $this->makeSession(
            'team_mexicano',
            $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']),
            courts: 2,
        );
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);
        $firstPairs = collect($this->pairsOf($first))->flatten(1)
            ->map(fn ($p) => implode('+', $p))->sort()->values()->all();

        $first->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $second = $builder->generateRound($session->refresh());
        $secondPairs = collect($this->pairsOf($second))->flatten(1)
            ->map(fn ($p) => implode('+', $p))->sort()->values()->all();

        // Partnerships survive; only opponents change.
        $this->assertSame($firstPairs, $secondPairs);
        $this->assertDatabaseCount('session_teams', 4);
    }

    public function test_team_mexicano_matches_the_strongest_teams_together(): void
    {
        $session = $this->makeSession(
            'team_mexicano',
            $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']),
            courts: 2,
        );
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);
        $first->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        // Give one team a decisive lead.
        $teams = $session->refresh()->participants->groupBy('session_team_id');
        $ordered = $teams->values();

        foreach ($ordered as $index => $members) {
            foreach ($members as $member) {
                SessionStanding::query()->updateOrCreate(
                    ['session_id' => $session->id, 'participant_id' => $member->id],
                    ['points' => 100 - $index * 10, 'matches_played' => 1, 'updated_at' => now()],
                );
            }
        }

        $second = $builder->generateRound($session->refresh());
        $pairs = $this->pairsOf($second);

        // Court 1 should hold the two highest-scoring teams.
        $court1Names = collect($pairs[0])->flatten()->sort()->values()->all();
        $topTwo = $ordered->take(2)->flatten()
            ->map(fn ($p) => $p->display_name_snapshot)->sort()->values()->all();

        $this->assertSame($topTwo, $court1Names);
    }

    // ---- mixicano ----------------------------------------------------------

    public function test_mixicano_still_enforces_one_man_and_one_woman(): void
    {
        $men = $this->players(['Bob', 'Carl'], PlayerGender::Male);
        $women = $this->players(['Dina', 'Eva', 'Fay'], PlayerGender::Female);

        $session = $this->makeSession(
            'mixicano',
            $men->concat($women),
            hostGender: PlayerGender::Male,
        );

        $round = app(SessionBuilder::class)->generateRound($session);
        $round->load('matches.teams.teamPlayers.participant.player');

        foreach ($round->matches as $match) {
            foreach ($match->teams as $team) {
                $genders = $team->teamPlayers
                    ->map(fn ($tp) => $tp->participant->player->gender)
                    ->all();

                $this->assertContains(PlayerGender::Male, $genders);
                $this->assertContains(PlayerGender::Female, $genders);
            }
        }
    }

    public function test_mixicano_balances_the_court_by_rank(): void
    {
        $men = $this->players(['Bob', 'Carl'], PlayerGender::Male);
        $women = $this->players(['Dina', 'Eva'], PlayerGender::Female);

        $session = $this->makeSession(
            'mixicano',
            $men->concat($women),
            hostGender: PlayerGender::Male,
        );

        // Host(m)=1, Bob(m)=2 ... but only 2 men can play per court, so the
        // top two men are Host and Bob; top two women Dina and Eva.
        $this->setRanks($session, [
            'Host' => 1, 'Dina' => 2, 'Bob' => 3, 'Eva' => 4, 'Carl' => 5,
        ]);

        $round = app(SessionBuilder::class)->generateRound($session->refresh());
        $sides = collect($this->pairsOf($round)[0])
            ->map(fn ($p) => implode('+', $p))->sort()->values()->all();

        // Higher man partners the lower woman: Host+Eva vs Bob+Dina.
        $this->assertSame(['Bob+Dina', 'Eva+Host'], $sides);
    }
}
