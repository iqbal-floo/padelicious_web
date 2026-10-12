<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Roster statuses and tags.
 *
 * participant_status becomes the registration state a host manages:
 *   active  -> confirmed   (plays; the default for host-added and Reclub players)
 *   queued  -> waitlisted  (on the list, not playing yet)
 *   + requested            (asked to join; waiting for a host to decide)
 * withdrawn is unchanged. no_show stays in the type (Postgres cannot drop
 * an enum value) but is no longer used: it is now a tag.
 *
 * session_participants.tags holds labels a host sets that change behaviour:
 *   host    -> may manage the session (the creator always has it)
 *   no_show -> left out of round generation and hand-entered matches
 */
return new class extends Migration
{
    /**
     * ALTER TYPE ... ADD VALUE cannot run inside a transaction block that
     * later uses the value, so this migration manages its own statements.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->renameValue('active', 'confirmed');
        $this->renameValue('queued', 'waitlisted');
        DB::statement("ALTER TYPE participant_status ADD VALUE IF NOT EXISTS 'requested'");

        DB::statement("ALTER TABLE session_participants ADD COLUMN IF NOT EXISTS tags jsonb NOT NULL DEFAULT '[]'::jsonb");
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
              ALTER TABLE session_participants
                ADD CONSTRAINT session_participants_tags_array CHECK (jsonb_typeof(tags) = 'array');
            EXCEPTION
              WHEN duplicate_object THEN NULL;
            END
            $$;
        SQL);

        // Existing hosts keep their power as a tag.
        DB::statement("UPDATE session_participants SET tags = tags || '[\"host\"]'::jsonb WHERE role = 'host' AND NOT tags @> '[\"host\"]'::jsonb");

        // The old no_show status becomes the no_show tag on a confirmed player.
        DB::statement(<<<'SQL'
            UPDATE session_participants
               SET status = 'confirmed',
                   tags = CASE WHEN tags @> '["no_show"]'::jsonb THEN tags ELSE tags || '["no_show"]'::jsonb END
             WHERE status = 'no_show'
        SQL);
    }

    public function down(): void
    {
        DB::statement("UPDATE session_participants SET status = 'waitlisted' WHERE status = 'requested'");
        DB::statement('ALTER TABLE session_participants DROP CONSTRAINT IF EXISTS session_participants_tags_array');
        DB::statement('ALTER TABLE session_participants DROP COLUMN IF EXISTS tags');
        $this->renameValue('waitlisted', 'queued');
        $this->renameValue('confirmed', 'active');
    }

    /**
     * Rename an enum label only if it is still there, so the migration is
     * safe to re-run and works on a database built either way.
     */
    private function renameValue(string $from, string $to): void
    {
        DB::statement(<<<SQL
            DO $$
            BEGIN
              IF EXISTS (
                SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
                 WHERE t.typname = 'participant_status' AND e.enumlabel = '{$from}'
              ) AND NOT EXISTS (
                SELECT 1 FROM pg_enum e JOIN pg_type t ON t.oid = e.enumtypid
                 WHERE t.typname = 'participant_status' AND e.enumlabel = '{$to}'
              ) THEN
                ALTER TYPE participant_status RENAME VALUE '{$from}' TO '{$to}';
              END IF;
            END
            $$;
        SQL);
    }
};
