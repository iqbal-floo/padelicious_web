<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 4 of docs/schema.sql — rounds, matches, and scoring.
 * match_points is the event log backing undo/audit/replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE rounds (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              session_id   uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
              round_number integer NOT NULL CHECK (round_number > 0),
              status       round_status NOT NULL DEFAULT 'pending',
              generated_at timestamptz NOT NULL DEFAULT now(),
              completed_at timestamptz,
              UNIQUE (session_id, round_number)
            );

            CREATE TABLE matches (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              round_id     uuid NOT NULL REFERENCES rounds(id) ON DELETE CASCADE,
              court_id     uuid NOT NULL REFERENCES courts(id) ON DELETE CASCADE,
              status       match_status NOT NULL DEFAULT 'scheduled',
              games_a      smallint NOT NULL DEFAULT 0 CHECK (games_a >= 0),
              games_b      smallint NOT NULL DEFAULT 0 CHECK (games_b >= 0),
              points_a     text NOT NULL DEFAULT '0',
              points_b     text NOT NULL DEFAULT '0',
              serving_team team_side,
              winner_team  team_side,
              started_at   timestamptz,
              finished_at  timestamptz,
              created_at   timestamptz NOT NULL DEFAULT now(),
              updated_at   timestamptz NOT NULL DEFAULT now(),
              UNIQUE (round_id, court_id),
              CONSTRAINT matches_winner_only_when_finished CHECK (
                winner_team IS NULL OR status = 'finished'
              )
            );

            CREATE TRIGGER trg_matches_updated BEFORE UPDATE ON matches
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE INDEX idx_matches_round ON matches (round_id);
            CREATE INDEX idx_matches_live  ON matches (status) WHERE status = 'live';

            CREATE TABLE match_teams (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              match_id     uuid NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
              side         team_side NOT NULL,
              score_points integer NOT NULL DEFAULT 0 CHECK (score_points >= 0),
              UNIQUE (match_id, side)
            );

            CREATE TABLE match_team_players (
              match_team_id  uuid NOT NULL REFERENCES match_teams(id) ON DELETE CASCADE,
              participant_id uuid NOT NULL REFERENCES session_participants(id) ON DELETE CASCADE,
              slot           smallint NOT NULL CHECK (slot IN (1, 2)),
              PRIMARY KEY (match_team_id, slot),
              UNIQUE (match_team_id, participant_id)
            );

            CREATE INDEX idx_mtp_participant ON match_team_players (participant_id);

            -- Score event log: source of truth for undo, audit, and replay.
            CREATE TABLE match_points (
              id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              match_id              uuid NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
              sequence              integer NOT NULL CHECK (sequence > 0),
              scoring_team          team_side NOT NULL,
              serving_team          team_side,
              points_a_before       text NOT NULL,
              points_b_before       text NOT NULL,
              games_a_before        smallint NOT NULL,
              games_b_before        smallint NOT NULL,
              is_game_point         boolean NOT NULL DEFAULT false,
              is_golden_point       boolean NOT NULL DEFAULT false,
              recorded_by_player_id uuid REFERENCES players(id) ON DELETE SET NULL,
              recorded_at           timestamptz NOT NULL DEFAULT now(),
              voided_at             timestamptz,
              UNIQUE (match_id, sequence)
            );

            CREATE INDEX idx_match_points_match_seq ON match_points (match_id, sequence DESC);
            CREATE INDEX idx_match_points_live ON match_points (match_id, sequence DESC)
              WHERE voided_at IS NULL;

            CREATE TABLE match_substitutions (
              id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              match_id           uuid NOT NULL REFERENCES matches(id) ON DELETE CASCADE,
              match_team_id      uuid NOT NULL REFERENCES match_teams(id) ON DELETE CASCADE,
              slot               smallint NOT NULL CHECK (slot IN (1, 2)),
              out_participant_id uuid NOT NULL REFERENCES session_participants(id),
              in_participant_id  uuid NOT NULL REFERENCES session_participants(id),
              at_sequence        integer,
              created_at         timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT subs_different_players CHECK (out_participant_id <> in_participant_id)
            );

            CREATE INDEX idx_subs_match ON match_substitutions (match_id);
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS match_substitutions CASCADE');
        DB::statement('DROP TABLE IF EXISTS match_points CASCADE');
        DB::statement('DROP TABLE IF EXISTS match_team_players CASCADE');
        DB::statement('DROP TABLE IF EXISTS match_teams CASCADE');
        DB::statement('DROP TABLE IF EXISTS matches CASCADE');
        DB::statement('DROP TABLE IF EXISTS rounds CASCADE');
    }
};
