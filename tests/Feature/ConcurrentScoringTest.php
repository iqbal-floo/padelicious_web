<?php

namespace Tests\Feature;

use App\Enums\TeamSide;
use App\Models\MatchPoint;
use App\Models\Player;
use App\Models\User;
use App\Services\ScoringService;
use App\Services\SessionBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two devices on one court scoring at the same time must not corrupt the
 * event log: ScoringService takes a row lock and assigns the next
 * sequence inside the transaction, with UNIQUE(match_id, sequence) as the
 * database-level backstop. This test covers that constraint.
 *
 * True multi-process parallelism cannot be tested under RefreshDatabase
 * (a second connection cannot see the uncommitted fixture), so it was
 * verified out-of-band: 10 concurrent OS processes against one match
 * produced 10 events with sequences 1-10, no gaps or duplicates.
 */
class ConcurrentScoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_unique_constraint_rejects_a_duplicate_sequence(): void
    {
        $host = User::factory()->create();
        $hostPlayer = Player::query()->create([
            'user_id' => $host->id, 'display_name' => 'Host', 'initials' => 'HO',
        ]);
        $others = collect(['AA', 'BB', 'CC'])->map(
            fn ($i) => Player::query()->create(['display_name' => $i, 'initials' => $i])
        );
        $session = app(SessionBuilder::class)->create(
            [
                'name' => 'Dup', 'match_type_code' => 'americano',
                'scheduled_date' => now()->toDateString(), 'court_count' => 1,
                'scoring_type' => 'normal', 'points_target' => 24,
            ],
            $others->map(fn ($p) => ['player_id' => $p->id])->all(),
            $hostPlayer->id,
        );
        $match = app(SessionBuilder::class)->generateRound($session)->matches->first();

        app(ScoringService::class)->recordPoint($match, TeamSide::A);

        // Forcing a duplicate sequence must violate UNIQUE(match_id, sequence).
        $this->expectException(UniqueConstraintViolationException::class);

        MatchPoint::query()->create([
            'match_id' => $match->id,
            'sequence' => 1,
            'scoring_team' => TeamSide::B,
            'points_a_before' => '0',
            'points_b_before' => '0',
            'games_a_before' => 0,
            'games_b_before' => 0,
            'recorded_at' => now(),
        ]);
    }
}
