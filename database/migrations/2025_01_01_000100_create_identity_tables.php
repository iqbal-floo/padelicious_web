<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 1 of docs/schema.sql — identity & authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
              id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              email              citext NOT NULL UNIQUE,
              email_verified_at  timestamptz,
              password_hash      text,
              full_name          text NOT NULL,
              phone              text,
              status             user_status NOT NULL DEFAULT 'active',
              accepted_terms_at  timestamptz,
              last_login_at      timestamptz,
              created_at         timestamptz NOT NULL DEFAULT now(),
              updated_at         timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT users_email_format CHECK (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$')
            );

            CREATE TRIGGER trg_users_updated BEFORE UPDATE ON users
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE TABLE user_auth_identities (
              id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              user_id          uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              provider         auth_provider NOT NULL,
              provider_user_id text NOT NULL,
              email            citext,
              raw_profile      jsonb,
              created_at       timestamptz NOT NULL DEFAULT now(),
              UNIQUE (provider, provider_user_id)
            );

            CREATE INDEX idx_auth_identities_user ON user_auth_identities (user_id);

            CREATE TABLE user_sessions (
              id                 uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              user_id            uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              refresh_token_hash text NOT NULL UNIQUE,
              device_name        text,
              user_agent         text,
              ip_address         inet,
              remember_me        boolean NOT NULL DEFAULT false,
              expires_at         timestamptz NOT NULL,
              revoked_at         timestamptz,
              created_at         timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX idx_user_sessions_active ON user_sessions (user_id)
              WHERE revoked_at IS NULL;

            CREATE TABLE password_reset_tokens (
              id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              token_hash text NOT NULL UNIQUE,
              expires_at timestamptz NOT NULL,
              used_at    timestamptz,
              created_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE INDEX idx_reset_tokens_user ON password_reset_tokens (user_id)
              WHERE used_at IS NULL;
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS password_reset_tokens CASCADE');
        DB::statement('DROP TABLE IF EXISTS user_sessions CASCADE');
        DB::statement('DROP TABLE IF EXISTS user_auth_identities CASCADE');
        DB::statement('DROP TABLE IF EXISTS users CASCADE');
    }
};
