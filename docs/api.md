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

### Usernames

Every player has a unique `username` (lowercase letters, digits, `.` and
`_`, 2–30 characters; `citext`, so case-insensitive). Emails and usernames
are unique; display names and full names may repeat. Registration
requires a `username`; players created any other way (seeders, Google
sign-in) get one derived from their name (`rina.hartono`,
`rina.hartono2`). `GET /players?search=` matches usernames as well as
names, so `@iqbal.permana` finds the right Iqbal.

### Roster: statuses and tags

Every participant has a **status** and a list of **tags**.

| status       | meaning                                   | set by |
|--------------|-------------------------------------------|--------|
| `confirmed`  | playing; the only status put into matches | host-added and Reclub players start here; a host can set it |
| `waitlisted` | on the list, not playing                  | a host; Reclub waitlists import as this |
| `requested`  | asked to join, waiting for a host         | `POST /sessions/{session}/join` |
| `withdrawn`  | left (history kept)                       | `DELETE …/participants/{p}` |

| tag       | effect |
|-----------|--------|
| `host`    | may manage the session (roster, rounds, scores). The creator always has it and cannot lose it; any participant can be promoted. |
| `no_show` | skipped by round generation and refused in hand-entered matches, until removed |

```
PATCH /api/v1/sessions/{session}/participants/{participant}   {status?: confirmed|waitlisted, tags?: [host, no_show]}
POST  /api/v1/sessions/{session}/join                          → requested
```

`tags` replaces the whole list. Sessions carry `can_manage` (may the
signed-in user manage it) and `my_participant` ({id, status} or null);
participants carry `tags` and `is_creator`. The session list's
`participant_count` counts confirmed players.

### Importing from Reclub

```
POST /api/v1/reclub/import   {code: "P828W9"}
```

Reads the public meet page `https://reclub.co/m/{code}` (Reclub has no
public API) and returns `{code, title, url, players: [{status, player}]}`:
everyone on the Confirmed and Waitlisted lists, each as a player here —
matched by `reclub_username` (their `/players/@handle`) or created as a
guest. Invited players are a count only and are not imported. Every import
is logged in `reclub_imports`. Unknown codes are a 422.

The client then creates the session with those players as
`participants: [{player_id, source: "reclub", status}]` — confirmed stay
confirmed, Reclub's waitlist stays waitlisted.

### Adding a player by hand

```
POST /api/v1/players   {display_name, username, gender?, initials?, skill_level?}
```

Creates a guest player — no account, no email — so a host can put
someone who has not signed up onto a roster (then
`POST /sessions/{session}/participants`). `is_guest` is true for them.
A taken username is a 422 on `username`.

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

## Order of play (printable sheet)

```
GET /sessions/{session}/order-of-play            HTML, print-friendly (public by uuid)
GET /sessions/{session}/order-of-play?format=pdf PDF download
GET /api/v1/sessions/{session}/order-of-play     JSON rows + html_url/pdf_url (auth)
```

One row per match in round → court order, for every match type:
`no`, `round`, `time`, `court`, `category` (`M`/`W`/`Mix`/`–`, from the
players' genders), `team_a`, `team_b`, `games_a`/`games_b` (null until the
match starts, so a fresh sheet prints empty boxes), `winner`,
`golden_point` (the format can finish level) and `decided_by_golden_point`.

`?start=08:15&slot=15` adds a time column: one slot per round from the
start time. Sessions also carry `order_of_play_url`.

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

### Entering a round by hand

`POST /api/v1/sessions/{session}/rounds` with a body enters the round
instead of generating it — a pre-written inter-club sheet, or a Mexicano
round the organiser picks:

```json
{
  "round_number": 3,
  "matches": [
    {"court": 1, "team_a": ["<participant>", "<participant>"], "team_b": ["…", "…"]},
    {"court": 2, "team_a": ["…", "…"], "team_b": ["…", "…"], "tennis_mode": "total4"}
  ]
}
```

- `round_number` is optional; a schedule can skip slots (a men's-only
  session with nothing at 11:15). Reusing a number is a 422.
- Participants must be active in the session and appear at most once
  per round; courts must exist and be used once per round. Otherwise
  422 with a message naming the match (`Match 2: Ana is listed twice…`).
- Team formats (`team_*`): the pair recorded in a player's first match is
  their partnership for the session; listing them with someone else is
  a 422.
- `tennis_mode` per match overrides the session's rule, so one sheet can
  play the men to 5 games and the women to 4. Null means the session's.
- A round entered while another is still open is created `pending`;
  completing a round promotes the next pending one to `in_progress`.
  Automatic generation (`POST` with no body) is refused (409) until the
  hand-entered rounds are complete.

Matches expose `tennis_mode` (null = session rule), and the order of
play's `format` line lists every rule in use.

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

### Match formats (when a match ends)

Derived from the session's `scoring_type` + `tennis_mode`
(`App\Services\MatchFormat`):

| session                      | rule                                                             |
|------------------------------|------------------------------------------------------------------|
| `tennis` + `raceN`           | first side to N games                                            |
| `tennis` + `totalN`, N odd   | exactly N games; more games wins (3-2, 4-1, 5-0 for `total5`)    |
| `tennis` + `totalN`, N even  | exactly N games; if level, **one golden point** decides the match (the score stays e.g. 2-2, `winner_team` is set) |
| `normal` (+ `points_target`) | not implemented yet — plays a set to 6 games like before         |

The decider is logged as a normal `match_points` event with
`is_golden_point = true` and `games_*_before` level, so undo reopens the
match at 2-2.

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
- **Points-target scoring** — `normal` sessions store `points_target`
  (16/21/24/32) but still score 0/15/30/40 to a six-game set.
- **Generated inter-club schedules** — a club-v-club sheet can be entered
  by hand (above) but is not generated: there is no match type that pairs
  only across clubs.

## Testing

```sh
php artisan test
```

Tests run against a real PostgreSQL database, not SQLite — the schema
uses enums, triggers, generated columns and GIN indexes that SQLite
cannot represent. See `phpunit.xml`.

Two local databases, on purpose:

| database              | used by                                   | contents                         |
|-----------------------|-------------------------------------------|----------------------------------|
| `padelicious_phpunit` | `phpunit.xml` → `RefreshDatabase`         | dropped and rebuilt by every run |
| `padelicious_test`    | `.env.testing` (`APP_ENV=testing artisan serve`) | seeded demo data for manual/app testing |

`RefreshDatabase` runs `migrate:fresh`, so the suite must never point at
the seeded database.

### Sparring simulation

```sh
php artisan db:seed --class=SparringRosterSeeder          # 32 players from the sheet
APP_ENV=testing php artisan serve                          # against padelicious_test
php scripts/simulate_sparring.php --out=/tmp               # two Team Americano sessions, auto-generated
php scripts/simulate_sparring_sheet.php --out=/tmp         # the printed sheet, entered by hand: 1 session, 43 matches
```

Creates the two Team Americano sessions from the Padelicious vs Terpadel
order of play (men: 10 pairs, 2 courts, `total5`; women: 6 pairs, 1 court,
`total4`), enters every point over HTTP until each pair has met every
other, then downloads each order of play as PDF.
