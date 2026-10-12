<?php

namespace Tests\Feature;

use App\Enums\MatchStatus;
use App\Enums\TeamSide;
use App\Models\Player;
use App\Models\Round;
use App\Models\SessionParticipant;
use App\Models\User;
use App\Services\SessionBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * team_koth with more players than slots: the bench rotation must not
 * split a fixed partnership.
 */
class KothTeamQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_koth_pairs_survive_queue_rotation(): void
    {
        $host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $host->id, 'display_name' => 'Host', 'initials' => 'HO',
        ]);

        // Host + 11 = 12 players on 2 courts: 8 play, 4 wait.
        $others = collect(range(2, 12))->map(fn ($i) => Player::query()->create([
            'display_name' => "P{$i}",
            // initials is char(2).
            'initials' => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        ]));

        $builder = app(SessionBuilder::class);
        $session = $builder->create([
            'name' => 'Team KOTH queue',
            'match_type_code' => 'team_koth',
            'scheduled_date' => now()->toDateString(),
            'court_count' => 2,
            'scoring_type' => 'normal',
            'points_target' => 24,
        ], $others->map(fn ($p) => ['player_id' => $p->id])->all(), $hostPlayer->id);

        $pairsOf = function (Round $r) {
            $r->load('matches.teams.teamPlayers.participant');

            return $r->matches->flatMap(fn ($m) => $m->teams->map(
                fn ($t) => $t->teamPlayers
                    ->map(fn ($tp) => $tp->participant->display_name_snapshot)
                    ->sort()->implode('+')
            ))->sort()->values()->all();
        };

        $settle = function (Round $r) {
            $r->load('matches');
            foreach ($r->matches as $m) {
                $m->forceFill([
                    'status' => MatchStatus::Finished,
                    'winner_team' => TeamSide::A,
                    'games_a' => 6, 'games_b' => 2, 'finished_at' => now(),
                ])->save();
            }
            $r->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        };

        $seen = [];

        for ($i = 0; $i < 3; $i++) {
            $round = $builder->generateRound($session->refresh());

            foreach ($pairsOf($round) as $pair) {
                $seen[] = $pair;
                // Each on-court pair must be a real session team, never a
                // half-pair stitched to someone from the queue.
                $this->assertStringContainsString('+', $pair);
            }

            $settle($round);
        }

        // Every pairing seen across all rounds must be one of the fixed teams.
        $teams = SessionParticipant::query()
            ->where('session_id', $session->id)
            ->whereNotNull('session_team_id')
            ->get()
            ->groupBy('session_team_id')
            ->map(fn ($m) => $m->pluck('display_name_snapshot')->sort()->implode('+'))
            ->values()
            ->all();

        $this->assertNotEmpty($teams, 'team_koth must persist its partnerships');

        foreach (array_unique($seen) as $pair) {
            $this->assertContains(
                $pair,
                $teams,
                "'{$pair}' is not a fixed team — the queue rotation split a partnership"
            );
        }
    }
}
