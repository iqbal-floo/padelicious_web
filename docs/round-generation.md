# Round generation: what's implemented, and what isn't

`SessionBuilder::generateRound()` dispatches on the match type's flags:

- **`is_team_based`** → fixed partnerships, reused every round
- **`is_mixed`** → every pair is one man + one woman
- otherwise → partners rotate each round (Americano-style)

## Implemented

| Code | Strategy | Notes |
|---|---|---|
| `americano` | rotation | Fewest-matches-played first, then `[1,3]` v `[2,4]` |
| `team_americano` | **fixed pairs** | Pairs created once in `session_teams`, reused for every later round; only opponents rotate, ordered by matches played so each team gets even time |
| `team_mexicano` | fixed pairs | Shares the team strategy. Still missing the Mexicano *ranking* rule for who plays whom |
| `team_koth` | fixed pairs | Shares the team strategy. Missing the king-court promotion/relegation rule |
| `mix_americano` | **mixed** | Requires every participant's gender; pairs M+F per court |
| `mixicano` | mixed | Shares the mixed strategy. Still missing the Mexicano ranking rule |

### Fixed partnerships

`session_teams` (session_id, name, team_number) plus
`session_participants.session_team_id`. Teams form on the first round from
the roster order, two players at a time, and a leftover odd player stays
unassigned and sits out. Once assigned, a player keeps that partner for
the session.

### Mixed formats

`mix_americano` and `mixicano` refuse to generate a round unless **every**
active participant has a gender set (`male` or `female`), naming how many
are missing. They also need at least 2 of each to fill a court. Gender is
set at registration (optional) or later via `PATCH /api/v1/profile`.

`other` and `undisclosed` cannot be paired by a rule that needs one of
each, so they are treated as unset.

## Still missing

| # | Code | What it needs |
|---|---|---|
| 1 | `mexicano` | Pair by **current rank** (1+4 v 2+3, 5+8 v 6+7). The fairness sort currently used fights this — Mexicano wants standings order |
| 2 | `mixicano` | Mexicano ranking on top of the mixed constraint (the gender half is done) |
| 3 | `team_mexicano` | Team standings deciding who plays whom (1st v 2nd), rather than matches-played rotation |
| 4 | `koth` | King/challenger courts, winners up and losers down. Not round-shaped: the `rounds` table models "generate N matches at once", which fits KOTH poorly |
| 5 | `team_koth` | Same, with fixed teams |

The three `club_*` types were **removed** (migration
`2025_01_01_001000`). They were seeded but never implemented —
`requires_club` was unenforced, so a "club" session could be created with
no club at all. `down()` restores them.

## Cross-cutting gaps

- **Partner-repeat avoidance** in `americano`: true Americano avoids
  repeating a partnership until everyone has partnered once. The sort does
  not track partnership history, so repeats happen early.
- **Odd rosters**: with 6 players on 1 court, two sit out and the sort
  picks who. Correct for Americano; KOTH and Mexicano have their own
  conventions. `session_queue` exists for this and is still unused.
- **`is_mixed` with `other`/`undisclosed`**: these players simply cannot
  join a mixed session. A three-way or preference-based rule would need a
  product decision.
