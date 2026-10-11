<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 6 of docs/schema.sql — Reclub imports & share links.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE reclub_imports (
              id                     uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              session_id             uuid REFERENCES sessions(id) ON DELETE SET NULL,
              requested_by_player_id uuid REFERENCES players(id) ON DELETE SET NULL,
              match_code             text NOT NULL,
              status                 import_status NOT NULL DEFAULT 'pending',
              imported_count         integer NOT NULL DEFAULT 0,
              error_message          text,
              raw_response           jsonb,
              created_at             timestamptz NOT NULL DEFAULT now(),
              completed_at           timestamptz
            );

            CREATE INDEX idx_reclub_imports_code    ON reclub_imports (match_code);
            CREATE INDEX idx_reclub_imports_session ON reclub_imports (session_id);

            CREATE TABLE share_links (
              id                   uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              token                text NOT NULL UNIQUE,
              resource_type        share_resource NOT NULL,
              session_id           uuid REFERENCES sessions(id) ON DELETE CASCADE,
              match_id             uuid REFERENCES matches(id) ON DELETE CASCADE,
              access               share_access NOT NULL DEFAULT 'view',
              created_by_player_id uuid REFERENCES players(id) ON DELETE SET NULL,
              expires_at           timestamptz,
              revoked_at           timestamptz,
              view_count           integer NOT NULL DEFAULT 0,
              created_at           timestamptz NOT NULL DEFAULT now(),
              -- Exactly one resource target must be populated.
              CONSTRAINT share_links_one_target CHECK (
                (resource_type IN ('session', 'leaderboard') AND session_id IS NOT NULL AND match_id IS NULL)
                OR
                (resource_type = 'match' AND match_id IS NOT NULL)
              )
            );

            CREATE INDEX idx_share_links_session ON share_links (session_id)
              WHERE revoked_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS share_links CASCADE');
        DB::statement('DROP TABLE IF EXISTS reclub_imports CASCADE');
    }
};
