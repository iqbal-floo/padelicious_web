# Round generation: what's implemented, and the 10 formats that aren't

`SessionBuilder::generateRound()` currently implements **one** algorithm and
applies it to every match type. It sorts the active roster by matches played
(fewest first), then by points descending, fills each court with four
players, and pairs them `[1,3]` vs `[2,4]`.

That is a reasonable **Americano** rotation. It is wrong, or merely
arbitrary, for the other ten.

## The one that works

| Code | Status |
|---|---|
| `americano` | **Implemented.** Everyone partners with everyone; fewest-played-first is the right fairness rule. |

## The 10 that need their own algorithms

### Team formats — partners must stay together

The current code re-pairs players every round, which breaks the core rule
of a team format: a team is fixed for the whole session.

| # | Code | What it needs |
|---|---|---|
| 1 | `team_americano` | Partners fixed at setup; rotate **teams** against each other, ideally a round-robin so every team meets every other once. Needs a team identity that outlives the round — the schema has no `session_teams` table, so this needs either a new table or a convention on `match_teams`. |
| 2 | `team_mexicano` | Fixed teams, but pairings decided by **team standings** (1st plays 2nd, 3rd plays 4th), recomputed each round. |
| 3 | `team_koth` | Fixed teams; winners advance toward the "king" court, losers drop. Court identity matters, not just the pairing. |

### Mexicano — ranking-driven pairings

| # | Code | What it needs |
|---|---|---|
| 4 | `mexicano` | After round 1, pair by **current rank**: 1+4 vs 2+3 on the top court, 5+8 vs 6+7 on the next, and so on. The current fewest-played sort actively fights this — Mexicano wants the standings order, not a fairness shuffle. |
| 5 | `mixicano` | Mexicano ranking **plus** the gender constraint below: each pair must be one man + one woman. |

### Mixed formats — need gender, which the client never sets

| # | Code | What it needs |
|---|---|---|
| 6 | `mix_americano` | Every pair must be one man + one woman, and partners should rotate within that constraint. Requires `players.gender` to be populated — the column exists and defaults to `undisclosed`, and **the Flutter app has no gender field at all**, so this format cannot work until the client collects it. |
| 7 | `mixicano` | (counted above) |

### King of the Court — a different shape entirely

| # | Code | What it needs |
|---|---|---|
| 8 | `koth` | Not round-based in the same way: a king court plus challenger courts, winners move up, losers move down, and play is often continuous rather than in discrete rounds. The `rounds` table models "generate N matches at once", which fits KOTH poorly. |

### Club formats — roster scoped to club membership

| # | Code | What it needs |
|---|---|---|
| 9 | `club_americano` | Americano, but the roster must be drawn from (and validated against) `club_members` with `status = 'active'`. `match_types.requires_club` is `true`, and **nothing currently enforces it** — a club session can be created with no `club_id`. |
| 10 | `club_mexicano` | Mexicano ranking + the same club-membership constraint. |
| — | `club_team_americano` | Team Americano + club constraint (overlaps #1 and #9). |

## Cross-cutting gaps

- **No strategy dispatch.** `generateRound()` ignores `match_type_code`
  entirely. The natural fix is a `RoundStrategy` interface resolved from the
  code, so each format is testable in isolation.
- **`requires_club` is unenforced** (3 formats affected).
- **`is_mixed` is unenforced**, and is unenforceable until the client
  captures gender (2 formats affected).
- **`is_team_based` is unenforced**; there is no persistent team concept
  (4 formats affected).
- **Odd rosters.** With 6 active players and 1 court, two sit out and the
  current sort decides who — correct for Americano, but KOTH and Mexicano
  have their own conventions for who waits. `session_queue` exists for this
  and is currently unused.
- **Partner-repeat avoidance.** True Americano tries not to repeat a
  partnership until everyone has partnered once. The current sort does not
  track partnership history, so repeats happen early.
