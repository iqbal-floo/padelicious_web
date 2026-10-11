<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 7 of docs/schema.sql — helper views.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Session summaries for sessions_screen (Active / Past tabs).
            CREATE VIEW v_session_summaries AS
            SELECT
              s.id,
              s.name,
              s.icon,
              s.status,
              s.scheduled_date,
              mt.label                       AS match_type_label,
              c.name                         AS club_name,
              COUNT(sp.id) FILTER (WHERE sp.status <> 'withdrawn') AS player_count
            FROM sessions s
            JOIN match_types mt ON mt.code = s.match_type_code
            LEFT JOIN clubs c ON c.id = s.club_id
            LEFT JOIN session_participants sp ON sp.session_id = s.id
            GROUP BY s.id, mt.label, c.name;

            -- Live state per court for kotc_screen.
            CREATE VIEW v_live_courts AS
            SELECT
              r.session_id,
              r.round_number,
              co.court_number,
              m.id AS match_id,
              m.status,
              m.games_a,
              m.games_b,
              m.points_a,
              m.points_b,
              m.serving_team
            FROM matches m
            JOIN rounds r  ON r.id = m.round_id
            JOIN courts co ON co.id = m.court_id;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_live_courts');
        DB::statement('DROP VIEW IF EXISTS v_session_summaries');
    }
};
