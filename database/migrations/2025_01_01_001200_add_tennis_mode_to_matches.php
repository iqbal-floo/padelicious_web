<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A match may carry its own format, overriding the session's tennis_mode.
 * An inter-club sparring day plays the men to 5 games and the women to 4
 * on the same sheet; without this the session could only hold one rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE matches ADD COLUMN IF NOT EXISTS tennis_mode tennis_mode');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE matches DROP COLUMN IF EXISTS tennis_mode');
    }
};
