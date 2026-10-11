<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Session-level teams for the team_* formats.
 *
 * Team Americano and friends fix partnerships at setup and keep them for
 * the whole session, so the pairing has to outlive a single round (which
 * match_teams does not).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE session_teams (
              id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              session_id  uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
              name        text NOT NULL,
              team_number smallint NOT NULL CHECK (team_number > 0),
              created_at  timestamptz NOT NULL DEFAULT now(),
              updated_at  timestamptz NOT NULL DEFAULT now(),
              UNIQUE (session_id, team_number)
            );

            CREATE TRIGGER trg_session_teams_updated BEFORE UPDATE ON session_teams
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE INDEX idx_session_teams_session ON session_teams (session_id);

            -- A participant belongs to at most one team. NULL for the
            -- individual formats (americano, mexicano, koth).
            ALTER TABLE session_participants
              ADD COLUMN session_team_id uuid
                REFERENCES session_teams(id) ON DELETE SET NULL;

            CREATE INDEX idx_participants_team ON session_participants (session_team_id)
              WHERE session_team_id IS NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE session_participants DROP COLUMN IF EXISTS session_team_id');
        DB::statement('DROP TABLE IF EXISTS session_teams CASCADE');
    }
};
