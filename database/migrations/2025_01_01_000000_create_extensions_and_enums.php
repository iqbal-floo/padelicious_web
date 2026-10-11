<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensions, enum types, and the shared updated_at trigger function.
 * Mirrors the header of docs/schema.sql.
 */
return new class extends Migration
{
    /**
     * Enum name => allowed values, per schema.sql.
     */
    private array $enums = [
        'user_status' => ['active', 'suspended', 'deleted'],
        'auth_provider' => ['google', 'apple', 'facebook', 'reclub'],
        'player_gender' => ['male', 'female', 'other', 'undisclosed'],
        'club_visibility' => ['public', 'private', 'invite_only'],
        'club_role' => ['owner', 'admin', 'member'],
        'membership_status' => ['active', 'pending', 'left', 'banned'],
        'scoring_type' => ['normal', 'tennis'],
        'tennis_mode' => [
            'race3', 'race4', 'race5', 'race6', 'race7',
            'total3', 'total4', 'total5', 'total6', 'total7',
        ],
        'leaderboard_sort' => ['points', 'wins'],
        'session_status' => ['draft', 'active', 'completed', 'cancelled'],
        'participant_role' => ['host', 'admin', 'player'],
        'participant_source' => ['manual', 'club', 'reclub'],
        'participant_status' => ['active', 'queued', 'withdrawn'],
        'round_status' => ['pending', 'in_progress', 'completed'],
        'match_status' => ['scheduled', 'live', 'finished', 'abandoned'],
        'team_side' => ['a', 'b'],
        'import_status' => ['pending', 'success', 'failed'],
        'share_resource' => ['session', 'leaderboard', 'match'],
        'share_access' => ['view', 'score'],
    ];

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        foreach ($this->enums as $name => $values) {
            $labels = implode(', ', array_map(fn ($v) => "'{$v}'", $values));

            // Idempotent: Postgres has no CREATE TYPE IF NOT EXISTS, and a
            // rolled-back test database can still hold the type.
            DB::unprepared(<<<SQL
                DO $$
                BEGIN
                  CREATE TYPE {$name} AS ENUM ({$labels});
                EXCEPTION
                  WHEN duplicate_object THEN NULL;
                END
                $$;
            SQL);
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$
            BEGIN
              NEW.updated_at = now();
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS set_updated_at() CASCADE');

        foreach (array_keys($this->enums) as $name) {
            DB::statement("DROP TYPE IF EXISTS {$name} CASCADE");
        }
    }
};
