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
| `team_mexicano` | fixed pairs + **rank** | Pairs fixed; strongest teams matched against each other by combined points |
| `mexicano` | **rank** | Roster in standings order; each court takes four and pairs 1st+4th v 2nd+3rd |
| `team_koth` | fixed pairs | Shares the team strategy. Missing the king-court promotion/relegation rule |
| `mix_americano` | **mixed** | Requires every participant's gender; pairs M+F per court |
| `mixicano` | mixed + **rank** | M+F pairs, balanced by standings (higher man with lower woman) |

### Fixed partnerships

`session_teams` (session_id, name, team_number) plus
`session_participants.session_team_id`. Teams form on the first round from
the roster order, two players at a time, and a leftover odd player stays
unassigned and sits out. Once assigned, a player keeps that partner for
the session.

### Mexicano ranking

For the three Mexicano-category types the roster is sorted by
`session_standings.rank` (not by matches played — that fairness sort
actively fights the format). Each court then takes the next four players
and pairs **1st + 4th against 2nd + 3rd**, which puts the leader with the
weakest of the four and keeps matches close.

Round 1 has no standings yet, so unranked players sort last and the
roster order stands — the conventional seeding.

`team_mexicano` instead orders whole teams by combined points, so the
strongest teams meet. `mixicano` applies the balancing within the
gender constraint: the higher-ranked man partners the lower-ranked woman.

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
| 1 | `koth` | King/challenger courts, winners up and losers down. Not round-shaped: the `rounds` table models "generate N matches at once", which fits KOTH poorly |
| 2 | `team_koth` | Same, with fixed teams |

KOTH currently runs the generic rotation, so it plays like Americano.

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
