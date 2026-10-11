<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops the three club_* match types.
 *
 * They were seeded but never implemented: requires_club was unenforced,
 * so a "club" session could be created with no club at all. Removing
 * them until the club-scoped roster rules exist.
 */
return new class extends Migration
{
    private array $codes = [
        'club_team_americano',
        'club_americano',
        'club_mexicano',
    ];

    public function up(): void
    {
        // Guard against deleting a type a real session still references:
        // match_type_code is a FK, so this would fail anyway.
        $inUse = DB::table('sessions')
            ->whereIn('match_type_code', $this->codes)
            ->count();

        if ($inUse > 0) {
            throw new RuntimeException(
                "Cannot remove club match types: {$inUse} session(s) still use them."
            );
        }

        DB::table('match_types')->whereIn('code', $this->codes)->delete();
    }

    public function down(): void
    {
        DB::table('match_types')->insertOrIgnore([
            ['code' => 'club_team_americano', 'label' => 'Club Team Americano', 'category' => 'Club', 'is_team_based' => true, 'is_mixed' => false, 'requires_club' => true, 'sort_order' => 9],
            ['code' => 'club_americano', 'label' => 'Club Americano', 'category' => 'Club', 'is_team_based' => false, 'is_mixed' => false, 'requires_club' => true, 'sort_order' => 10],
            ['code' => 'club_mexicano', 'label' => 'Club Mexicano', 'category' => 'Club', 'is_team_based' => false, 'is_mixed' => false, 'requires_club' => true, 'sort_order' => 11],
        ]);
    }
};
