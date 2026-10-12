<?php

namespace Database\Seeders;

use App\Enums\PlayerGender;
use App\Models\Player;
use App\Models\PlayerStat;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The Padelicious vs Terpadel sparring roster (docs: Order of Play,
 * 18 Oct 2026): ten men's pairs and six women's pairs, listed partner
 * by partner so a team format forms the same pairs as the sheet.
 *
 * Used by scripts/simulate_sparring.php.
 */
class SparringRosterSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string}> */
    public const MEN_PAIRS = [
        ['Dikdoyo', 'Reyhan'], ['Akhmad', 'Fuad'], ['Mahdi', 'Afin'], ['Alan', 'Risan'], ['Ekkow', 'Nagib'],
        ['Hafid', 'Kautsar'], ['Ivan', 'Agil'], ['Hijrin', 'Denis'], ['Afkar', 'Hadzieq'], ['Rezha', 'Boy'],
    ];

    /** @var list<array{0: string, 1: string}> */
    public const WOMEN_PAIRS = [
        ['Fuji', 'Dini'], ['Risky', 'Aghnia'], ['Sarah', 'Erlina'],
        ['Lala', 'Shela'], ['Shafira', 'Nisa'], ['Azmil', 'Nadya'],
    ];

    /**
     * Padelicious reserves on the sheet; Iqbal already exists from the demo seed.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const RESERVES = [['Hardian', 'Iqbal']];

    public function run(): void
    {
        DB::transaction(function () {
            $colorIndex = 0;

            foreach ([
                [PlayerGender::Male, self::MEN_PAIRS],
                [PlayerGender::Female, self::WOMEN_PAIRS],
                [PlayerGender::Male, self::RESERVES],
            ] as [$gender, $pairs]) {
                foreach ($pairs as $pair) {
                    foreach ($pair as $name) {
                        // Idempotent: re-running must not duplicate the roster.
                        if (Player::query()->where('display_name', $name)->exists()) {
                            $colorIndex++;

                            continue;
                        }

                        $player = Player::query()->create([
                            'display_name' => $name,
                            'initials' => strtoupper(substr($name, 0, 2)),
                            'avatar_color_index' => $colorIndex++ % 8,
                            'gender' => $gender,
                            'skill_level' => 3.0,
                            'skill_level_label' => 'Intermediate',
                        ]);

                        PlayerStat::query()->create([
                            'player_id' => $player->id,
                            'sessions_played' => 0,
                            'matches_played' => 0,
                            'matches_won' => 0,
                            'matches_lost' => 0,
                            'total_points' => 0,
                        ]);
                    }
                }
            }
        });
    }
}
