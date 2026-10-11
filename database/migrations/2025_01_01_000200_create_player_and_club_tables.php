<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 2 of docs/schema.sql — players, clubs, and membership.
 * Includes the clubs.member_count counter-cache trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE players (
              id                  uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              user_id             uuid UNIQUE REFERENCES users(id) ON DELETE SET NULL,
              display_name        text NOT NULL,
              initials            char(2) NOT NULL,
              avatar_url          text,
              avatar_color_index  smallint NOT NULL DEFAULT 0
                CHECK (avatar_color_index BETWEEN 0 AND 7),
              skill_level         numeric(2,1) CHECK (skill_level BETWEEN 1.0 AND 5.0),
              skill_level_label   text,
              gender              player_gender NOT NULL DEFAULT 'undisclosed',
              is_guest            boolean GENERATED ALWAYS AS (user_id IS NULL) STORED,
              created_at          timestamptz NOT NULL DEFAULT now(),
              updated_at          timestamptz NOT NULL DEFAULT now()
            );

            CREATE TRIGGER trg_players_updated BEFORE UPDATE ON players
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE INDEX idx_players_name_trgm ON players USING gin (display_name gin_trgm_ops);

            CREATE TABLE clubs (
              id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              slug            text NOT NULL UNIQUE,
              name            text NOT NULL,
              emoji           text NOT NULL DEFAULT '🎾',
              theme_color     text,
              description     text NOT NULL DEFAULT '',
              city            text,
              visibility      club_visibility NOT NULL DEFAULT 'public',
              owner_player_id uuid REFERENCES players(id) ON DELETE SET NULL,
              member_count    integer NOT NULL DEFAULT 0 CHECK (member_count >= 0),
              created_at      timestamptz NOT NULL DEFAULT now(),
              updated_at      timestamptz NOT NULL DEFAULT now(),
              deleted_at      timestamptz
            );

            CREATE TRIGGER trg_clubs_updated BEFORE UPDATE ON clubs
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE INDEX idx_clubs_name_trgm ON clubs USING gin (name gin_trgm_ops);
            CREATE INDEX idx_clubs_visible ON clubs (visibility) WHERE deleted_at IS NULL;

            CREATE TABLE club_members (
              club_id   uuid NOT NULL REFERENCES clubs(id)   ON DELETE CASCADE,
              player_id uuid NOT NULL REFERENCES players(id) ON DELETE CASCADE,
              role      club_role NOT NULL DEFAULT 'member',
              status    membership_status NOT NULL DEFAULT 'active',
              joined_at timestamptz NOT NULL DEFAULT now(),
              left_at   timestamptz,
              PRIMARY KEY (club_id, player_id)
            );

            CREATE INDEX idx_club_members_player ON club_members (player_id)
              WHERE status = 'active';

            -- A club may only have one active owner.
            CREATE UNIQUE INDEX idx_club_single_owner ON club_members (club_id)
              WHERE role = 'owner' AND status = 'active';

            -- Counter cache for clubs.member_count
            CREATE OR REPLACE FUNCTION sync_club_member_count() RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'INSERT' THEN
                IF NEW.status = 'active' THEN
                  UPDATE clubs SET member_count = member_count + 1 WHERE id = NEW.club_id;
                END IF;
              ELSIF TG_OP = 'DELETE' THEN
                IF OLD.status = 'active' THEN
                  UPDATE clubs SET member_count = member_count - 1 WHERE id = OLD.club_id;
                END IF;
              ELSIF NEW.status IS DISTINCT FROM OLD.status THEN
                IF NEW.status = 'active' THEN
                  UPDATE clubs SET member_count = member_count + 1 WHERE id = NEW.club_id;
                ELSIF OLD.status = 'active' THEN
                  UPDATE clubs SET member_count = member_count - 1 WHERE id = NEW.club_id;
                END IF;
              END IF;
              RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_club_member_count
              AFTER INSERT OR UPDATE OR DELETE ON club_members
              FOR EACH ROW EXECUTE FUNCTION sync_club_member_count();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS sync_club_member_count() CASCADE');
        DB::statement('DROP TABLE IF EXISTS club_members CASCADE');
        DB::statement('DROP TABLE IF EXISTS clubs CASCADE');
        DB::statement('DROP TABLE IF EXISTS players CASCADE');
    }
};
