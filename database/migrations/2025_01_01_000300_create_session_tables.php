<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 3 of docs/schema.sql — sessions & match configuration.
 *
 * Note: `sessions` here means a padel play session, not a Laravel HTTP session.
 * SESSION_DRIVER is set to `file` so the framework does not claim this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE match_types (
              code          text PRIMARY KEY,
              label         text NOT NULL,
              category      text NOT NULL,
              is_team_based boolean NOT NULL DEFAULT false,
              is_mixed      boolean NOT NULL DEFAULT false,
              requires_club boolean NOT NULL DEFAULT false,
              sort_order    smallint NOT NULL DEFAULT 0
            );

            INSERT INTO match_types (code, label, category, is_team_based, is_mixed, requires_club, sort_order) VALUES
              ('americano',           'Americano',              'Americano', false, false, false, 1),
              ('team_americano',      'Team Americano',         'Americano', true,  false, false, 2),
              ('mix_americano',       'Mix Americano',          'Americano', false, true,  false, 3),
              ('mexicano',            'Mexicano',               'Mexicano',  false, false, false, 4),
              ('team_mexicano',       'Team Mexicano',          'Mexicano',  true,  false, false, 5),
              ('mixicano',            'Mixicano',               'Mexicano',  false, true,  false, 6),
              ('koth',                'King of the Court',      'KOTH',      false, false, false, 7),
              ('team_koth',           'Team King of the Court', 'KOTH',      true,  false, false, 8),
              ('club_team_americano', 'Club Team Americano',    'Club',      true,  false, true,  9),
              ('club_americano',      'Club Americano',         'Club',      false, false, true, 10),
              ('club_mexicano',       'Club Mexicano',          'Club',      false, false, true, 11);

            CREATE TABLE sessions (
              id                uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              club_id           uuid REFERENCES clubs(id) ON DELETE SET NULL,
              host_player_id    uuid NOT NULL REFERENCES players(id),
              name              text NOT NULL,
              icon              text NOT NULL DEFAULT '🎾',
              match_type_code   text NOT NULL REFERENCES match_types(code),
              scheduled_date    date NOT NULL,
              court_count       smallint NOT NULL DEFAULT 1
                CHECK (court_count BETWEEN 1 AND 4),
              scoring_type      scoring_type NOT NULL DEFAULT 'normal',
              points_target     smallint CHECK (points_target IN (16, 21, 24, 32)),
              tennis_mode       tennis_mode,
              golden_point      boolean NOT NULL DEFAULT true,
              leaderboard_sort  leaderboard_sort NOT NULL DEFAULT 'points',
              status            session_status NOT NULL DEFAULT 'draft',
              started_at        timestamptz,
              completed_at      timestamptz,
              created_at        timestamptz NOT NULL DEFAULT now(),
              updated_at        timestamptz NOT NULL DEFAULT now(),
              -- normal scoring uses points_target; tennis uses tennis_mode.
              CONSTRAINT sessions_scoring_consistent CHECK (
                (scoring_type = 'normal' AND tennis_mode IS NULL)
                OR
                (scoring_type = 'tennis' AND points_target IS NULL)
              )
            );

            CREATE TRIGGER trg_sessions_updated BEFORE UPDATE ON sessions
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE INDEX idx_sessions_status_date ON sessions (status, scheduled_date DESC);
            CREATE INDEX idx_sessions_club        ON sessions (club_id, scheduled_date DESC);
            CREATE INDEX idx_sessions_host        ON sessions (host_player_id, scheduled_date DESC);

            CREATE TABLE session_participants (
              id                          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              session_id                  uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
              player_id                   uuid NOT NULL REFERENCES players(id),
              display_name_snapshot       text NOT NULL,
              initials_snapshot           char(2) NOT NULL,
              avatar_color_index_snapshot smallint NOT NULL DEFAULT 0,
              role                        participant_role NOT NULL DEFAULT 'player',
              source                      participant_source NOT NULL DEFAULT 'manual',
              status                      participant_status NOT NULL DEFAULT 'active',
              joined_at                   timestamptz NOT NULL DEFAULT now(),
              UNIQUE (session_id, player_id)
            );

            CREATE INDEX idx_participants_player  ON session_participants (player_id);
            CREATE INDEX idx_participants_session ON session_participants (session_id, status);

            CREATE TABLE courts (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              session_id   uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
              court_number smallint NOT NULL CHECK (court_number BETWEEN 1 AND 4),
              label        text,
              UNIQUE (session_id, court_number)
            );

            CREATE TABLE session_queue (
              id             uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              session_id     uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
              participant_id uuid NOT NULL REFERENCES session_participants(id) ON DELETE CASCADE,
              position       integer NOT NULL CHECK (position > 0),
              enqueued_at    timestamptz NOT NULL DEFAULT now(),
              dequeued_at    timestamptz,
              -- DEFERRABLE so reordering the queue does not fail mid-transaction.
              CONSTRAINT session_queue_position_unique UNIQUE (session_id, position)
                DEFERRABLE INITIALLY DEFERRED
            );

            CREATE INDEX idx_queue_active ON session_queue (session_id, position)
              WHERE dequeued_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS session_queue CASCADE');
        DB::statement('DROP TABLE IF EXISTS courts CASCADE');
        DB::statement('DROP TABLE IF EXISTS session_participants CASCADE');
        DB::statement('DROP TABLE IF EXISTS sessions CASCADE');
        DB::statement('DROP TABLE IF EXISTS match_types CASCADE');
    }
};
