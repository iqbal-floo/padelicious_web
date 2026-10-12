<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * players.username: the unique handle for every player, registered or
 * guest. Display names may repeat ("Iqbal" twice is fine); usernames and
 * emails may not. Existing players are backfilled from their name
 * (iqbal, iqbal2, …) so the column can be NOT NULL from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE players ADD COLUMN IF NOT EXISTS username citext');

        $taken = [];

        foreach (DB::table('players')->orderBy('created_at')->orderBy('id')->get(['id', 'display_name']) as $player) {
            $base = $this->slug($player->display_name);
            $candidate = $base;

            for ($n = 2; isset($taken[$candidate]); $n++) {
                $candidate = $base.$n;
            }

            $taken[$candidate] = true;
            DB::table('players')->where('id', $player->id)->update(['username' => $candidate]);
        }

        DB::statement('ALTER TABLE players ALTER COLUMN username SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS players_username_unique ON players (username)');
        // Lowercase letters, digits, dot and underscore; 2–30 characters.
        DB::statement("ALTER TABLE players ADD CONSTRAINT players_username_format CHECK (username::text ~ '^[a-z0-9][a-z0-9._]{1,29}$')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE players DROP CONSTRAINT IF EXISTS players_username_format');
        DB::statement('DROP INDEX IF EXISTS players_username_unique');
        DB::statement('ALTER TABLE players DROP COLUMN IF EXISTS username');
    }

    /**
     * Same rule as Player::usernameFrom(), kept inline so the migration
     * does not depend on application code that may change later.
     */
    private function slug(string $name): string
    {
        $slug = trim(preg_replace('/[^a-z0-9._]+/', '.', mb_strtolower(trim($name))) ?? '', '.');

        if (strlen($slug) < 2) {
            $slug = 'player';
        }

        return substr($slug, 0, 30);
    }
};
