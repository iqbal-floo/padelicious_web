<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Enums\TeamSide;
use App\Models\MatchGame;
use App\Models\Player;
use App\Models\Round;
use App\Models\Session;
use App\Models\SessionQueue;
use App\Models\User;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

class KothFormatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return Collection<int, Player>
     */
    private function players(array $names): Collection
    {
        return collect($names)->map(fn ($n) => Player::query()->create([
            'display_name' => $n,
            'initials' => strtoupper(substr($n, 0, 2)),
        ]));
    }

    private function makeSession(string $type, Collection $roster, int $courts): Session
    {
        $host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $host->id, 'display_name' => 'Host', 'initials' => 'HO',
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
     * Decide every match in a round, then close it.
     *
     * @param  array<int, string>  $winners  court number => 'a' or 'b'
     */
    private function settle(Round $round, array $winners): void
    {
        $round->load('matches.court');

        foreach ($round->matches as $match) {
            $side = $winners[$match->court->court_number] ?? 'a';

            $match->forceFill([
                'status' => MatchStatus::Finished,
                'winner_team' => TeamSide::from($side),
                'games_a' => $side === 'a' ? 6 : 2,
                'games_b' => $side === 'b' ? 6 : 2,
                'finished_at' => now(),
            ])->save();
        }

        $round->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
    }

    /**
     * @return array<int, list<string>> court number => participant names
     */
    private function courtRosters(Round $round): array
    {
        $round->load('matches.court', 'matches.teams.teamPlayers.participant');

        $out = [];

        foreach ($round->matches as $match) {
            $names = $match->teams
                ->flatMap(fn ($t) => $t->teamPlayers->map(
                    fn ($tp) => $tp->participant->display_name_snapshot
                ))
                ->sort()
                ->values()
                ->all();

            $out[$match->court->court_number] = $names;
        }

        ksort($out);

        return $out;
    }

    /**
     * @return list<string> names on the winning side of a court
     */
    private function winnersOn(Round $round, int $courtNumber, string $side): array
    {
        $round->load('matches.court', 'matches.teams.teamPlayers.participant');

        $match = $round->matches->first(fn ($m) => $m->court->court_number === $courtNumber);
        $team = $match->teams->first(fn ($t) => $t->side->value === $side);

        return $team->teamPlayers
            ->map(fn ($tp) => $tp->participant->display_name_snapshot)
            ->sort()->values()->all();
    }

    public function test_round_one_seeds_courts_in_roster_order(): void
    {
        // Host + 7 = 8 players over 2 courts.
        $session = $this->makeSession('koth', $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']), 2);

        $round = app(SessionBuilder::class)->generateRound($session);

        $this->assertCount(2, $round->matches);

        $courts = $this->courtRosters($round);
        $this->assertCount(4, $courts[1]);
        $this->assertCount(4, $courts[2]);

        // No overlap between courts.
        $this->assertEmpty(array_intersect($courts[1], $courts[2]));
    }

    public function test_winners_move_up_a_court_and_losers_move_down(): void
    {
        $session = $this->makeSession('koth', $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']), 2);
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);

        // Court 2's side A wins, so they should be promoted to court 1.
        $promoted = $this->winnersOn($first, 2, 'a');
        // Court 1's side B loses, so they should drop to court 2.
        $relegated = $this->winnersOn($first, 1, 'b');

        $this->settle($first, [1 => 'a', 2 => 'a']);

        $second = $builder->generateRound($session->refresh());
        $courts = $this->courtRosters($second);

        foreach ($promoted as $name) {
            $this->assertContains($name, $courts[1], "{$name} won on court 2 and should be promoted");
        }

        foreach ($relegated as $name) {
            $this->assertContains($name, $courts[2], "{$name} lost on court 1 and should be relegated");
        }
    }

    public function test_winners_on_the_king_court_stay_there(): void
    {
        $session = $this->makeSession('koth', $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']), 2);
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);
        $kings = $this->winnersOn($first, 1, 'a');

        $this->settle($first, [1 => 'a', 2 => 'a']);

        $second = $builder->generateRound($session->refresh());
        $courts = $this->courtRosters($second);

        foreach ($kings as $name) {
            $this->assertContains($name, $courts[1], "{$name} defended court 1 and should stay");
        }
    }

    public function test_losers_on_the_lowest_court_stay_there(): void
    {
        $session = $this->makeSession('koth', $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']), 2);
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);
        // Court 2 side B loses; there is no court 3 to drop to.
        $bottom = $this->winnersOn($first, 2, 'b');

        $this->settle($first, [1 => 'a', 2 => 'a']);

        $second = $builder->generateRound($session->refresh());
        $courts = $this->courtRosters($second);

        foreach ($bottom as $name) {
            $this->assertContains($name, $courts[2], "{$name} is already on the lowest court");
        }
    }

    public function test_waiting_players_are_queued_and_rotate_in(): void
    {
        // Host + 9 = 10 players on 2 courts: 8 play, 2 wait.
        $session = $this->makeSession(
            'koth',
            $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9', 'P10']),
            2,
        );
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);

        // Exactly two players sit out, and they are recorded in the queue.
        $queued = SessionQueue::query()
            ->where('session_id', $session->id)
            ->whereNull('dequeued_at')
            ->count();

        $this->assertSame(2, $queued, 'the two players not on a court should be queued');

        $playing = collect($this->courtRosters($first))->flatten()->all();
        $this->assertCount(8, $playing);

        $waitingNames = SessionQueue::query()
            ->where('session_id', $session->id)
            ->whereNull('dequeued_at')
            ->with('participant')
            ->get()
            ->map(fn ($q) => $q->participant->display_name_snapshot)
            ->all();

        // Nobody is both playing and waiting.
        $this->assertEmpty(array_intersect($playing, $waitingNames));

        $this->settle($first, [1 => 'a', 2 => 'a']);

        $second = $builder->generateRound($session->refresh());
        $nowPlaying = collect($this->courtRosters($second))->flatten()->all();

        // The players who waited get court time in the next round.
        foreach ($waitingNames as $name) {
            $this->assertContains($name, $nowPlaying, "{$name} waited and should now play");
        }
    }

    public function test_the_queue_is_rewritten_each_round(): void
    {
        $session = $this->makeSession(
            'koth',
            $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9', 'P10']),
            2,
        );
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);
        $this->settle($first, [1 => 'a', 2 => 'a']);
        $builder->generateRound($session->refresh());

        // Still exactly 2 active queue entries, not an accumulating pile.
        $this->assertSame(
            2,
            SessionQueue::query()->where('session_id', $session->id)
                ->whereNull('dequeued_at')->count()
        );

        // The queue is rebuilt each round rather than accumulating:
        // session_queue_position_unique covers (session_id, position) and
        // ignores dequeued_at, so stale rows would block position reuse.
        $this->assertSame(
            2,
            SessionQueue::query()->where('session_id', $session->id)->count(),
            'the queue should hold only the current round\'s waiting players'
        );
    }

    public function test_a_single_court_session_keeps_everyone_on_court_one(): void
    {
        $session = $this->makeSession('koth', $this->players(['P2', 'P3', 'P4']), 1);
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);
        $this->settle($first, [1 => 'a']);

        $second = $builder->generateRound($session->refresh());

        // Nowhere to promote or relegate to; the round still generates.
        $this->assertCount(1, $second->matches);
        $this->assertCount(4, collect($this->courtRosters($second))->flatten());
    }

    public function test_koth_needs_four_players(): void
    {
        $session = $this->makeSession('koth', $this->players(['P2', 'P3']), 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('At least 4 active players are required.');

        app(SessionBuilder::class)->generateRound($session);
    }

    public function test_team_koth_keeps_its_fixed_pairs_while_promoting(): void
    {
        $session = $this->makeSession('team_koth', $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']), 2);
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);

        $pairsOf = function (Round $r) {
            $r->load('matches.teams.teamPlayers.participant');

            return $r->matches
                ->flatMap(fn ($m) => $m->teams->map(
                    fn ($t) => $t->teamPlayers
                        ->map(fn ($tp) => $tp->participant->display_name_snapshot)
                        ->sort()->implode('+')
                ))
                ->sort()->values()->all();
        };

        $firstPairs = $pairsOf($first);
        $this->settle($first, [1 => 'a', 2 => 'a']);

        $second = $builder->generateRound($session->refresh());

        $this->assertSame(
            $firstPairs,
            $pairsOf($second),
            'team_koth must keep its fixed partnerships'
        );
    }

    public function test_an_undecided_previous_round_still_generates(): void
    {
        $session = $this->makeSession('koth', $this->players(['P2', 'P3', 'P4', 'P5', 'P6', 'P7', 'P8']), 2);
        $builder = app(SessionBuilder::class);

        $first = $builder->generateRound($session);

        // Close the round without deciding a winner (abandoned matches).
        MatchGame::query()->whereIn('round_id', [$first->id])->update(['status' => 'abandoned']);
        $first->forceFill(['status' => 'completed', 'completed_at' => now()])->save();

        $second = $builder->generateRound($session->refresh());

        $this->assertCount(2, $second->matches);
        $this->assertCount(8, collect($this->courtRosters($second))->flatten());
    }
}
