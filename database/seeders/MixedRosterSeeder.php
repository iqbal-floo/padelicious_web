<?php

namespace Database\Seeders;

use App\Enums\PlayerGender;
use App\Models\Player;
use App\Models\PlayerStat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A gendered roster for the mixed formats.
 *
 * mix_americano and mixicano require every player's gender to be set and
 * need at least 2 men and 2 women per court, so DemoSessionSeeder's
 * 8 undisclosed players cannot exercise them. Eight of each, with graded
 * skill levels, gives Mexicano-style ranking something to sort by.
 */
class MixedRosterSeeder extends Seeder
{
    /** @var list<array{0:string, 1:string, 2:float}> */
    private const MEN = [
        ['Arif', 'AR', 4.5], ['Bayu', 'BY', 4.0], ['Candra', 'CN', 3.5], ['Dimas', 'DM', 3.5],
        ['Eko', 'EK', 3.0], ['Fajar', 'FJ', 3.0], ['Gilang', 'GL', 2.5], ['Hadi', 'HD', 2.0],
    ];

    /** @var list<array{0:string, 1:string, 2:float}> */
    private const WOMEN = [
        ['Intan', 'IN', 4.5], ['Jessica', 'JS', 4.0], ['Kirana', 'KR', 3.5], ['Laras', 'LR', 3.5],
        ['Maya', 'MY', 3.0], ['Nadia', 'ND', 3.0], ['Olivia', 'OL', 2.5], ['Putri', 'PT', 2.0],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            $colorIndex = 0;

            foreach ([[PlayerGender::Male, self::MEN], [PlayerGender::Female, self::WOMEN]] as [$gender, $roster]) {
                foreach ($roster as [$name, $initials, $skill]) {
                    // Idempotent: re-running must not duplicate the roster.
                    if (Player::query()->where('display_name', $name)->exists()) {
                        continue;
                    }

                    $player = Player::query()->create([
                        'display_name' => $name,
                        'initials' => $initials,
                        'avatar_color_index' => $colorIndex % 8,
                        'gender' => $gender,
                        'skill_level' => $skill,
                        'skill_level_label' => $this->label($skill),
                    ]);

                    PlayerStat::query()->create([
                        'player_id' => $player->id,
                        'sessions_played' => 0,
                        'matches_played' => 0,
                        'matches_won' => 0,
                        'matches_lost' => 0,
                        'total_points' => 0,
                    ]);

                    $colorIndex++;
                }
            }
        });
    }

    private function label(float $skill): string
    {
        return match (true) {
            $skill >= 4.5 => 'Advanced',
            $skill >= 3.5 => 'Upper Intermediate',
            $skill >= 2.5 => 'Intermediate',
            default => 'Beginner',
        };
    }
}
