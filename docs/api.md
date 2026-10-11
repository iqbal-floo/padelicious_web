# Padelicious API (v1)

Base URL: `/api/v1` · Auth: Sanctum bearer tokens · All responses JSON.

Stack: Laravel 13.35 · Filament 5.10 · PHP 8.4 · PostgreSQL 17.

## Authentication

```http
POST /api/v1/auth/login
{ "email": "...", "password": "...", "device_name": "flutter-android" }
```

Returns `{ "token": "...", "user": { id, email, full_name, status } }`.
Send it on every other request:

```http
Authorization: Bearer <token>
```

Rate limited to 6 attempts/minute. Wrong credentials return `422` with a
generic message (it does not reveal whether the email exists).

| Method | Path | Notes |
|---|---|---|
| POST | `/auth/register` | Creates user + player profile, returns a token. `201` |
| POST | `/auth/login` | Issue token |
| POST | `/auth/google` | Exchange a Google access token for an API token. `503` until credentials are configured — see `docs/google-sso.md` |
| GET | `/auth/me` | Current user + linked player profile |
| PATCH | `/profile` | Update the signed-in user and their player profile (full_name, display_name, **gender**, skill_level, avatar_color_index) |
| POST | `/auth/logout` | Revokes only the calling token |

### Registering

```json
POST /api/v1/auth/register
{
  "full_name": "Iqbal Ramadhan",
  "email": "you@email.com",
  "password": "secret123",
  "password_confirmation": "secret123",
  "device_name": "flutter-android"
}
```

Creates the user **and their player profile** in one transaction — the
profile is what sessions, standings and club membership reference. The
display name is the first word of the full name and initials are derived
from it, so "Iqbal Ramadhan" becomes `Iqbal` / `IR`.

`gender` is optional (`male`, `female`, `other`, `undisclosed`) and
defaults to `undisclosed`. The mixed formats require `male` or `female`
for every participant, so an existing account sets it via
`PATCH /profile`.

Email uniqueness is case-insensitive (the column is `citext`). Register
and login are each throttled to 6 requests/minute.

### Match types

There are **8**: americano, team_americano, mix_americano, mexicano,
team_mexicano, mixicano, koth, team_koth. The three `club_*` types were
removed — see `docs/round-generation.md`.

## Resources

All read endpoints require authentication and return `401` otherwise.

| Method | Path | Notes |
|---|---|---|
| GET | `/match-types` | 11 static match types, ordered by `sort_order` |
| GET | `/players` | Paginated. `?search=` (trigram index), `?registered_only=1`, `?per_page=` (max 100) |
| GET | `/players/{id}` | Includes lifetime stats and clubs |
| GET | `/clubs` | Paginated. `?search=`, `?city=` |
| GET | `/clubs/{id}` | Includes owner and active members |
| GET | `/sessions` | Paginated. `?status=active\|past`, `?club_id=` |
| GET | `/sessions/{id}` | Includes match type, club, host, participants |
| GET | `/sessions/{id}/rounds` | Rounds → matches → teams → players (live court data) |
| GET | `/sessions/{id}/leaderboard` | Standings, ordered by the session's `leaderboard_sort` |

## Public live score (no auth)

A spectator needs only the match uuid — unguessable, and read-only.

| Method | Path | Notes |
|---|---|---|
| GET | `/api/v1/live/matches/{id}` | One score snapshot as JSON |
| GET | `/api/v1/live/matches/{id}/stream` | **Server-Sent Events**; pushes only when the score changes |
| GET | `/live/{id}` | The shareable spectator scoreboard page (HTML) |

The SSE stream emits `event: score` with the same payload as the snapshot,
plus `: keep-alive` comments to hold the connection through proxies. It
reconnects client-side automatically and closes itself after 30 minutes.

**Serving the stream needs a multi-worker server.** `php artisan serve` is
single-threaded: one open SSE connection blocks every other request. Use
`PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t public public/index.php`
locally, or php-fpm/Octane in production.

## Writes

Every write below requires the caller to be the session's **host** or a
participant with the **admin** role, else `403`.

| Method | Path | Notes |
|---|---|---|
| POST | `/sessions` | Creates the session, its courts, and the roster. Host is added automatically. Returns `201` |
| POST | `/sessions/{id}/complete` | Recalculates standings, marks the session completed |
| DELETE | `/sessions/{id}` | **Cancels** (status `cancelled`) — never deletes, so history survives |
| POST | `/sessions/{id}/participants` | Adds a player, snapshotting their appearance. `409` if already on the roster |
| DELETE | `/sessions/{id}/participants/{pid}` | **Withdraws** (status `withdrawn`), not a delete |
| POST | `/sessions/{id}/rounds` | Generates the next round, pairing players across courts. `409` if a round is still open or fewer than 4 active players |
| POST | `/rounds/{id}/complete` | Closes the round and recalculates standings |
| POST | `/matches/{id}/points` | Records one point. Body: `{"team":"a"\|"b"}` |
| DELETE | `/matches/{id}/points` | Undoes the last point. `409` if there is nothing to undo |

### Creating a session

```json
POST /api/v1/sessions
{
  "name": "Friday Night Americano",
  "match_type_code": "americano",
  "scheduled_date": "2026-10-20",
  "court_count": 2,
  "scoring_type": "normal",
  "points_target": 24,
  "golden_point": true,
  "participants": [{ "player_id": "<uuid>", "source": "manual" }]
}
```

`scoring_type: normal` takes `points_target` (16/21/24/32);
`scoring_type: tennis` takes `tennis_mode`. Setting both is a `422` —
this mirrors the database's `sessions_scoring_consistent` CHECK.

### Scoring rules

Points run `0 → 15 → 30 → 40`, then the game, matching the Flutter
client. At 40-40:

- **golden point on** (default): the next point wins the game.
- **golden point off**: the scorer takes advantage (`"GP"`); losing it
  returns to 40-40.

Six games wins the set, which finishes the match and sets `winner_team`.

`match_points` is an **append-only event log** and the source of truth.
Undo *voids* the last event (`voided_at`) and replays the survivors, so
it correctly restores a game across a game boundary. The cached
`games_a`/`points_a` columns on `matches` are derived from that replay.

Concurrency: the match row is locked for update and the sequence assigned
inside the transaction, with `UNIQUE(match_id, sequence)` as a backstop.
Verified with 10 concurrent processes: 10 events, sequences 1-10, no gaps
or duplicates.

`?status=active` groups `draft` + `active`; `?status=past` groups
`completed` + `cancelled`, matching the app's Active/Past tabs.

## Notes for the Flutter client

- All ids are **uuid strings**, not ints.
- Participants carry `display_name`/`initials`/`avatar_color_index` from
  the join-time **snapshot**, so historic sessions render as they were.
- `avatar_color_index` (0–7) maps to `AppColors.chipPairs*`.
- Enums serialise as their string values (`"active"`, `"live"`, `"a"`).
- Timestamps are ISO 8601; `scheduled_date` is `YYYY-MM-DD`.
- List endpoints are paginated: data in `data`, cursors in `links`/`meta`.

## Not implemented yet

- **Substitutions** (`match_substitutions`) — the switch-player sheet.
- **Waiting queue** (`session_queue`) — enqueue/dequeue endpoints.
- **Share links** (`share_links`) — public read-only session views.
- **Reclub import** (`reclub_imports`) — currently mocked in the client.
- **Registration / social login** — only password login exists; the
  schema's `user_auth_identities` and `user_sessions` are unused.
- **Round rotation strategies** — `generateRound` implements one
  Americano-style pairing (fewest matches played first, `[1,3] v [2,4]`);
  Mexicano, KOTH and team variants need their own algorithms.

## Testing

```sh
php artisan test
```

Tests run against a real PostgreSQL database (`padelicious_test`), not
SQLite — the schema uses enums, triggers, generated columns and GIN
indexes that SQLite cannot represent. See `phpunit.xml`.
