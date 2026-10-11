# Flutter → backend: what is actually wired

Audited after adding registration. The short answer: **only the two auth
screens talk to the backend.** Everything else reads `AppState`, which
still constructs `Mock*` repositories by default.

## Wired to the API

| Screen | Endpoint |
|---|---|
| `login_screen` | `POST /api/v1/auth/login` |
| `register_screen` | `POST /api/v1/auth/register` |

Both show inline field errors from the backend's 422 response, disable
their button while in flight, and store the returned Sanctum token.

## Not wired — still mock data

| Screen | What it needs | Endpoint that exists |
|---|---|---|
| `players_screen`, `player_detail_screen` | Swap in `ApiPlayerRepository` | `GET /players`, `GET /players/{id}` ✅ |
| `clubs_screen`, `club_detail_screen` | Swap in `ApiClubRepository` | `GET /clubs`, `GET /clubs/{id}` ✅ |
| `sessions_screen` | Swap in `ApiSessionRepository` | `GET /sessions?status=` ✅ |
| `setup_screen` | Create the session server-side | `POST /sessions` ✅ |
| `kotc_screen` | Load rounds/courts; generate next round | `GET/POST /sessions/{id}/rounds` ✅ |
| `score_screen` | Record and undo points | `POST/DELETE /matches/{id}/points` ✅ |
| `leaderboard_screen` | Read standings | `GET /sessions/{id}/leaderboard` ✅ |
| `profile_screen` | Read the signed-in player | `GET /auth/me` ✅ |
| `create_club_screen` | **No endpoint yet** | ❌ club create/join/leave |
| `forgot_password_screen` | **No endpoint yet** | ❌ password reset |

So the API already covers almost every screen; the gap is wiring, not
missing endpoints. The repository classes
(`ApiPlayerRepository`, `ApiClubRepository`, `ApiSessionRepository`)
already exist and implement the same interfaces as the mocks — swapping
them is a one-line change per repository in `AppState`'s constructor.

Two real blockers for a full swap:

1. **The screens key off player *names*, not uuids.** `fetchPlayerProfile`
   takes a `String name`, and `kotc`/`score` track players by display
   name. The API is uuid-addressed, so these signatures need to change.
2. **`AppState` holds live score in memory** (`gamesA`, `pointsA`,
   `scorePoint`, `undoPoint`) with its own history stack. The backend now
   owns that state via the `match_points` log, so `score_screen` should
   read from the API rather than keep a parallel copy.

## Missing endpoints

- `POST /clubs`, `POST /clubs/{id}/join`, `DELETE /clubs/{id}/leave` —
  `ApiClubRepository` throws `UnimplementedError` for these today.
- Password reset (the schema has `password_reset_tokens`, unused).
- Reclub import (schema has `reclub_imports`, client still mocks it).
