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
| POST | `/auth/login` | Issue token |
| GET | `/auth/me` | Current user + linked player profile |
| POST | `/auth/logout` | Revokes only the calling token |

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

Write operations: creating sessions, managing participants, recording
points (`match_points` undo/replay), generating rounds, substitutions,
share links, and Reclub import. Read endpoints only for now.
