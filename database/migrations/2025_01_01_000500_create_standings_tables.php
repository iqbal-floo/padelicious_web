<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 5 of docs/schema.sql — standings & statistics.
 * win_rate is a stored generated column in both tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE session_standings (
              session_id     uuid NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
              participant_id uuid NOT NULL REFERENCES session_participants(id) ON DELETE CASCADE,
              points         integer NOT NULL DEFAULT 0,
              wins           integer NOT NULL DEFAULT 0,
              losses         integer NOT NULL DEFAULT 0,
              draws          integer NOT NULL DEFAULT 0,
              matches_played integer NOT NULL DEFAULT 0,
              games_won      integer NOT NULL DEFAULT 0,
              games_lost     integer NOT NULL DEFAULT 0,
              win_rate       numeric(5,2) GENERATED ALWAYS AS (
                               CASE WHEN matches_played = 0 THEN 0
                                    ELSE ROUND(wins * 100.0 / matches_played, 2) END
                             ) STORED,
              rank           integer,
              updated_at     timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (session_id, participant_id)
            );

            CREATE INDEX idx_standings_rank ON session_standings (session_id, rank);

            CREATE TABLE player_stats (
              player_id       uuid PRIMARY KEY REFERENCES players(id) ON DELETE CASCADE,
              sessions_played integer NOT NULL DEFAULT 0,
              matches_played  integer NOT NULL DEFAULT 0,
              matches_won     integer NOT NULL DEFAULT 0,
              matches_lost    integer NOT NULL DEFAULT 0,
              total_points    integer NOT NULL DEFAULT 0,
              win_rate        numeric(5,2) GENERATED ALWAYS AS (
                                CASE WHEN matches_played = 0 THEN 0
                                     ELSE ROUND(matches_won * 100.0 / matches_played, 2) END
                              ) STORED,
              last_played_at  timestamptz,
              updated_at      timestamptz NOT NULL DEFAULT now()
            );
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS player_stats CASCADE');
        DB::statement('DROP TABLE IF EXISTS session_standings CASCADE');
    }
};
