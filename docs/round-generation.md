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
| `koth` | **promotion** | Court 1 is the king court; winners move up, losers down, queue rotates in |
| `team_koth` | **promotion** + fixed pairs | Same, with partnerships kept intact — pairs move between courts together |
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

### King of the Court

Court 1 is the king court. After each completed round the winning pair
moves one court up and the losing pair one down. Winners already on court
1 stay and defend; losers on the lowest court stay. Round 1 has no
results to promote from, so courts are seeded in roster order.

Promotion alone would fill every slot and leave the same players waiting
forever, so when anyone is benched the lowest court gives up places to
the queue, taking from the bottom up — the king court is never disturbed.

`session_queue` (previously unused) now holds exactly the players sitting
out the current round, ordered by position, which is what the client's
"N waiting" list shows. It is rebuilt each round rather than appended to:
`session_queue_position_unique` covers `(session_id, position)` and
ignores `dequeued_at`, so stale rows would block reusing position 1.

`team_koth` persists its partnerships in `session_teams` like the other
team formats, and both the promotion and the queue rotation move whole
pairs, never splitting one.

### Mixed formats

`mix_americano` and `mixicano` refuse to generate a round unless **every**
active participant has a gender set (`male` or `female`), naming how many
are missing. They also need at least 2 of each to fill a court. Gender is
set at registration (optional) or later via `PATCH /api/v1/profile`.

`other` and `undisclosed` cannot be paired by a rule that needs one of
each, so they are treated as unset.

## Still missing

All 8 match types now have their own rule. What remains is refinement
rather than missing behaviour:

- **Partner-repeat avoidance** in `americano`: true Americano avoids
  repeating a partnership until everyone has partnered once. The sort does
  not track partnership history, so repeats can happen early.
- **KOTH continuous play**: real KOTH is often played as a running game
  rather than discrete rounds. This implementation is round-based, which
  matches the client's "Generate next session" button but is not the only
  convention.
- **`is_mixed` with `other`/`undisclosed`**: those players cannot join a
  mixed session at all. A three-way or preference-based rule would need a
  product decision.

The three `club_*` types were **removed** (migration
`2025_01_01_001000`). They were seeded but never implemented —
`requires_club` was unenforced, so a "club" session could be created with
no club at all. `down()` restores them.

