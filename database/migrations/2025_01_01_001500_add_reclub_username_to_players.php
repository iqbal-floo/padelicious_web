<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * players.reclub_username: the player's handle on reclub.co (from their
 * /players/@handle link), so importing the same Reclub meet again — or
 * another meet with the same people — reuses the player instead of
 * creating a duplicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE players ADD COLUMN IF NOT EXISTS reclub_username citext');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS players_reclub_username_unique ON players (reclub_username) WHERE reclub_username IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS players_reclub_username_unique');
        DB::statement('ALTER TABLE players DROP COLUMN IF EXISTS reclub_username');
    }
};
