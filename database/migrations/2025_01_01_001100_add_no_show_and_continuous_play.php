<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two additions driven by how sessions actually run:
 *
 *  - participant_status gains `no_show`, for someone on the roster who has
 *    not turned up. Unlike `withdrawn` it is meant to be reversible — they
 *    are simply skipped when generating rounds until they arrive.
 *
 *  - sessions.continuous_play, for King of the Court played as a running
 *    game rather than discrete rounds: the next match on a court starts as
 *    soon as that court finishes, without waiting for the whole round.
 */
return new class extends Migration
{
    /**
     * ALTER TYPE ... ADD VALUE cannot be used in the same transaction that
     * creates it, and Laravel wraps Postgres migrations in one by default.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        // Idempotent: IF NOT EXISTS is supported from PG 12, which is the
        // oldest server this schema runs on (db.floo.id is 12.22).
        DB::statement("ALTER TYPE participant_status ADD VALUE IF NOT EXISTS 'no_show'");

        DB::statement(
            'ALTER TABLE sessions ADD COLUMN IF NOT EXISTS continuous_play boolean NOT NULL DEFAULT false'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sessions DROP COLUMN IF EXISTS continuous_play');

        // Postgres cannot drop a value from an enum. Any participant left
        // marked no_show is returned to the roster so the column stays
        // readable if the type is ever rebuilt from the enum migration.
        DB::statement("UPDATE session_participants SET status = 'active' WHERE status = 'no_show'");
    }
};
