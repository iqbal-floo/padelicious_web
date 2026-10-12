<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantSource;
use App\Enums\ParticipantStatus;
use App\Enums\ParticipantTag;
use App\Enums\PlayerGender;
use App\Enums\RoundStatus;
use App\Enums\SessionStatus;
use App\Enums\TeamSide;
use App\Enums\TennisMode;
use App\Exceptions\InvalidRoundException;
use App\Models\Court;
use App\Models\MatchGame;
use App\Models\MatchTeam;
use App\Models\MatchTeamPlayer;
use App\Models\Player;
use App\Models\Round;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\SessionQueue;
use App\Models\SessionStanding;
use App\Models\SessionTeam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SessionBuilder
{
    /**
     * Create a session with its courts and roster in one transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $participants
     */
    public function create(array $data, array $participants, string $hostPlayerId): Session
    {
        return DB::transaction(function () use ($data, $participants, $hostPlayerId) {
            $session = Session::query()->create([
                ...$data,
                'host_player_id' => $hostPlayerId,
                'status' => SessionStatus::Draft,
            ]);

            for ($number = 1; $number <= $session->court_count; $number++) {
                Court::query()->create([
                    'session_id' => $session->id,
                    'court_number' => $number,
                    'label' => "Court {$number}",
                ]);
            }

            // The host is always on the roster.
            $seen = [];
            $rows = [];
            foreach ([['player_id' => $hostPlayerId, 'role' => ParticipantRole::Host], ...$participants] as $entry) {
                $playerId = $entry['player_id'];
                if (isset($seen[$playerId])) {
                    continue;
                }
                $seen[$playerId] = true;
                $rows[] = $entry;
            }

            foreach ($rows as $entry) {
                $participant = $this->addParticipant(
                    $session,
                    $entry['player_id'],
                    $entry['role'] ?? ParticipantRole::Player,
                    isset($entry['source'])
                        ? ParticipantSource::from($entry['source'])
                        : ParticipantSource::Manual,
                    // The creator always plays; others may arrive waitlisted
                    // (a Reclub waitlist, for example).
                    $entry['player_id'] !== $hostPlayerId && isset($entry['status'])
                        ? ParticipantStatus::from($entry['status'])
                        : ParticipantStatus::Confirmed,
                );

                // The creator plays by default and is the first host.
                if ($entry['player_id'] === $hostPlayerId) {
                    $participant->forceFill(['tags' => [ParticipantTag::Host->value]])->save();
                }
            }

            return $session->refresh();
        });
    }

    /**
     * Add one player to a session, snapshotting their current appearance.
     */
    public function addParticipant(
        Session $session,
        string $playerId,
        ParticipantRole|string $role = ParticipantRole::Player,
        ParticipantSource|string $source = ParticipantSource::Manual,
        ParticipantStatus $status = ParticipantStatus::Confirmed,
    ): SessionParticipant {
        $player = Player::query()->findOrFail($playerId);

        $existing = SessionParticipant::query()
            ->where('session_id', $session->id)
            ->where('player_id', $playerId)
            ->first();

        if ($existing) {
            // Re-joining after withdrawing reactivates the same row.
            if ($existing->status === ParticipantStatus::Withdrawn) {
                $existing->forceFill(['status' => $status])->save();

                return $existing;
            }

            throw new RuntimeException('That player is already in this session.');
        }

        $participant = SessionParticipant::query()->create([
            'session_id' => $session->id,
            'player_id' => $player->id,
            // Snapshots so historic sessions render as they were.
            'display_name_snapshot' => $player->display_name,
            'initials_snapshot' => $player->initials,
            'avatar_color_index_snapshot' => $player->avatar_color_index,
            'role' => $role instanceof ParticipantRole ? $role : ParticipantRole::from($role),
            'source' => $source instanceof ParticipantSource ? $source : ParticipantSource::from($source),
            // Host-added and Reclub-imported players are confirmed; a
            // player asking to join comes in as requested.
            'status' => $status,
            'tags' => [],
            'joined_at' => now(),
        ]);

        SessionStanding::query()->firstOrCreate([
            'session_id' => $session->id,
            'participant_id' => $participant->id,
        ]);

        return $participant;
    }

    /**
     * Generate the next round, pairing players into matches across courts.
     *
     * Americano-style rotation: order the roster by matches played (then by
     * points) so players who have sat out are picked first, then fill each
     * court with four players as [1,3] vs [2,4].
     */
    public function generateRound(Session $session): Round
    {
        return DB::transaction(function () use ($session) {
            $session = Session::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $open = $session->rounds()
                ->where('status', '!=', RoundStatus::Completed->value)
                ->exists();

            if ($open) {
                throw new RuntimeException('Finish the current round before generating another.');
            }

            $courts = $session->courts()->orderBy('court_number')->get();

            $standings = SessionStanding::query()
                ->where('session_id', $session->id)
                ->get()
                ->keyBy('participant_id');

            $matchTypeCategory = $session->matchType?->category;

            $roster = $session->participants()
                // Confirmed and not marked no-show.
                ->eligible()
                // player is needed for the mixed-gender constraint.
                ->with('player')
                // Join order is the seeding for round 1 and the order
                // fixed pairs are formed in, so make it explicit.
                ->orderBy('joined_at')
                ->orderBy('id')
                ->get();

            // One comparator returning a sortable tuple: passing an ARRAY of
            // closures to sortBy() does not order an Eloquent collection the
            // way it does a plain one, so build the key explicitly.
            $roster = ($matchTypeCategory === 'Mexicano'
                // Standings order: rank 1 first. Unranked (round 1) players
                // sort last, leaving the roster order intact.
                ? $roster->sortBy(fn ($p) => sprintf(
                    '%010d:%010d',
                    $standings[$p->id]->rank ?? PHP_INT_MAX,
                    PHP_INT_MAX - ($standings[$p->id]->points ?? 0),
                ))
                // Everything else: fewest matches played first, for fairness.
                : $roster->sortBy(fn ($p) => sprintf(
                    '%010d:%010d',
                    $standings[$p->id]->matches_played ?? 0,
                    PHP_INT_MAX - ($standings[$p->id]->points ?? 0),
                )))->values();

            if ($roster->count() < 4) {
                throw new RuntimeException('At least 4 active players are required.');
            }

            $matchType = $session->matchType;

            $round = Round::query()->create([
                'session_id' => $session->id,
                'round_number' => (int) $session->rounds()->max('round_number') + 1,
                'status' => RoundStatus::InProgress,
                'generated_at' => now(),
            ]);

            // Each entry is [sideAParticipants, sideBParticipants].
            // Mexicano decides opponents by standings rather than by
            // evenness, and KOTH by court position, so both need their own
            // strategies.
            $isMexicano = $matchType?->category === 'Mexicano';
            $isKoth = $matchType?->category === 'KOTH';

            $pairings = match (true) {
                $isKoth => $this->kothPairings(
                    $session, $roster, $courts, $matchType->is_team_based,
                ),
                $isMexicano && $matchType->is_team_based => $this->teamPairings(
                    $session, $roster, $courts->count(), byRank: true,
                ),
                $isMexicano && $matchType->is_mixed => $this->mixedPairings(
                    $roster, $courts->count(), byRank: true,
                ),
                $isMexicano => $this->mexicanoPairings(
                    $session, $roster, $courts->count(),
                ),
                (bool) $matchType?->is_team_based => $this->teamPairings(
                    $session, $roster, $courts->count(),
                ),
                (bool) $matchType?->is_mixed => $this->mixedPairings(
                    $roster, $courts->count(),
                ),
                default => $this->rotationPairings($roster, $courts->count()),
            };

            foreach ($pairings as $i => [$sideA, $sideB]) {
                $this->persistMatch($round, $courts[$i], $sideA, $sideB);
            }

            $this->activate($session);

            return $round->load('matches.teams.players');
        });
    }

    /**
     * Enter a round by hand: the host names the court and the four players
     * of every match. This is how a pre-written schedule (an inter-club
     * sparring sheet) or a hand-picked Mexicano round gets in.
     *
     * Rounds entered while another is still open queue as `pending` and
     * are promoted when the open one completes, so a whole day can be
     * keyed in before the first ball.
     *
     * @param  list<array{court: int, team_a: list<string>, team_b: list<string>, tennis_mode?: ?string}>  $matches
     *
     * @throws InvalidRoundException
     */
    public function createRound(Session $session, array $matches, ?int $roundNumber = null): Round
    {
        return DB::transaction(function () use ($session, $matches, $roundNumber) {
            $session = Session::query()
                ->whereKey($session->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $courts = $session->courts()->get()->keyBy('court_number');

            $all = $session->participants()->get()->keyBy('id');
            $roster = $session->participants()->eligible()->get()->keyBy('id');

            $number = $roundNumber ?? (int) $session->rounds()->max('round_number') + 1;

            if ($session->rounds()->where('round_number', $number)->exists()) {
                throw new InvalidRoundException("Round {$number} already exists.");
            }

            $usedCourts = [];
            $listed = [];
            $prepared = [];

            foreach (array_values($matches) as $i => $entry) {
                $label = 'Match '.($i + 1);
                $courtNumber = (int) $entry['court'];

                $court = $courts[$courtNumber]
                    ?? throw new InvalidRoundException("{$label}: court {$courtNumber} does not exist in this session.");

                if (isset($usedCourts[$courtNumber])) {
                    throw new InvalidRoundException("{$label}: court {$courtNumber} is used twice in this round.");
                }
                $usedCourts[$courtNumber] = true;

                $sides = [];

                foreach (['team_a', 'team_b'] as $key) {
                    $members = [];

                    foreach ($entry[$key] as $id) {
                        $participant = $roster[$id]
                            ?? throw new InvalidRoundException($this->ineligibleReason($label, $id, $all[$id] ?? null));

                        if (isset($listed[$id])) {
                            throw new InvalidRoundException(
                                "{$label}: {$participant->display_name_snapshot} is listed twice in this round."
                            );
                        }
                        $listed[$id] = true;
                        $members[] = $participant;
                    }

                    $sides[] = $members;
                }

                $prepared[] = [
                    'court' => $court,
                    'sides' => $sides,
                    'tennis_mode' => isset($entry['tennis_mode']) ? TennisMode::from($entry['tennis_mode']) : null,
                ];
            }

            // Fixed-pair formats: a player keeps the partner they started with.
            if ($session->matchType?->is_team_based) {
                foreach ($prepared as $match) {
                    foreach ($match['sides'] as [$a, $b]) {
                        $this->ensurePartnership($session, $a, $b);
                    }
                }
            }

            $open = $session->rounds()
                ->where('status', '!=', RoundStatus::Completed->value)
                ->exists();

            $round = Round::query()->create([
                'session_id' => $session->id,
                'round_number' => $number,
                'status' => $open ? RoundStatus::Pending : RoundStatus::InProgress,
                'generated_at' => now(),
            ]);

            foreach ($prepared as $match) {
                $this->persistMatch($round, $match['court'], $match['sides'][0], $match['sides'][1], $match['tennis_mode']);
            }

            $this->activate($session);

            return $round->load('matches.teams.players');
        });
    }

    /**
     * Why a listed player cannot be put into a match, in words a host can act on.
     */
    private function ineligibleReason(string $label, string $id, ?SessionParticipant $participant): string
    {
        if (! $participant) {
            return "{$label}: {$id} is not on this session's roster.";
        }

        $name = $participant->display_name_snapshot;

        if ($participant->hasTag(ParticipantTag::NoShow)) {
            return "{$label}: {$name} is marked no-show.";
        }

        return match ($participant->status) {
            ParticipantStatus::Waitlisted => "{$label}: {$name} is waitlisted. Confirm them first.",
            ParticipantStatus::Requested => "{$label}: {$name} has only requested to join. Confirm them first.",
            ParticipantStatus::Withdrawn => "{$label}: {$name} has withdrawn from this session.",
            default => "{$label}: {$name} cannot play right now.",
        };
    }

    /**
     * One match on one court with its two sides, as the scoring and
     * export code expect to find it.
     *
     * @param  list<SessionParticipant>  $sideA
     * @param  list<SessionParticipant>  $sideB
     */
    private function persistMatch(
        Round $round,
        Court $court,
        array $sideA,
        array $sideB,
        ?TennisMode $tennisMode = null,
    ): MatchGame {
        $match = MatchGame::query()->create([
            'round_id' => $round->id,
            'court_id' => $court->id,
            'status' => MatchStatus::Scheduled,
            'serving_team' => TeamSide::A,
            'tennis_mode' => $tennisMode,
        ]);

        foreach ([[TeamSide::A, $sideA], [TeamSide::B, $sideB]] as [$side, $members]) {
            $team = MatchTeam::query()->create([
                'match_id' => $match->id,
                'side' => $side,
            ]);

            foreach (array_values($members) as $slot => $participant) {
                MatchTeamPlayer::query()->create([
                    'match_team_id' => $team->id,
                    'participant_id' => $participant->id,
                    'slot' => $slot + 1,
                ]);
            }
        }

        return $match;
    }

    /**
     * The first round, however it was made, starts the session.
     */
    private function activate(Session $session): void
    {
        if ($session->status === SessionStatus::Draft) {
            $session->forceFill([
                'status' => SessionStatus::Active,
                'started_at' => $session->started_at ?? now(),
            ])->save();
        }
    }

    /**
     * Record (or check) a fixed partnership for a hand-entered match.
     *
     * @throws InvalidRoundException when either player already has a different partner
     */
    private function ensurePartnership(Session $session, SessionParticipant $a, SessionParticipant $b): void
    {
        if ($a->session_team_id !== null || $b->session_team_id !== null) {
            if ($a->session_team_id === $b->session_team_id) {
                return;
            }

            [$fixed, $other] = $a->session_team_id !== null ? [$a, $b] : [$b, $a];
            $partner = SessionParticipant::query()
                ->where('session_team_id', $fixed->session_team_id)
                ->whereKeyNot($fixed->id)
                ->value('display_name_snapshot');

            throw new InvalidRoundException(
                "{$fixed->display_name_snapshot} already partners {$partner} in this session and cannot play with {$other->display_name_snapshot}."
            );
        }

        $team = SessionTeam::query()->create([
            'session_id' => $session->id,
            'team_number' => (int) SessionTeam::query()->where('session_id', $session->id)->max('team_number') + 1,
            'name' => $a->display_name_snapshot.' / '.$b->display_name_snapshot,
        ]);

        foreach ([$a, $b] as $participant) {
            $participant->forceFill(['session_team_id' => $team->id])->save();
        }
    }

    /**
     * Fixed partnerships (team_americano, team_mexicano, team_koth).
     *
     * Pairs are decided once — at setup, or on the first round — and reused
     * for every subsequent round. Only the opponents change.
     *
     * With $byRank (Team Mexicano) the strongest teams are matched against
     * each other; otherwise opponents rotate for even playing time.
     *
     * @param  Collection<int, SessionParticipant>  $roster
     * @return list<array{0: list<SessionParticipant>, 1: list<SessionParticipant>}>
     */
    private function teamPairings(
        Session $session,
        $roster,
        int $courtCount,
        bool $byRank = false,
    ): array {
        $teams = $this->ensureTeams($session, $roster);

        if (count($teams) < 2) {
            throw new RuntimeException('A team format needs at least 2 full teams.');
        }

        $standings = SessionStanding::query()
            ->where('session_id', $session->id)
            ->get()
            ->keyBy('participant_id');

        $ordered = collect($teams)
            ->sortBy(fn (array $members) => $byRank
                // Team Mexicano: strongest teams meet (1st v 2nd, 3rd v 4th),
                // so order by combined points, descending.
                ? -collect($members)->sum(fn ($p) => $standings[$p->id]->points ?? 0)
                // Otherwise rotate for evenness: fewest matches played first.
                : collect($members)->sum(fn ($p) => $standings[$p->id]->matches_played ?? 0))
            ->values()
            ->all();

        $playable = min($courtCount, intdiv(count($ordered), 2));

        if ($byRank) {
            $pairings = [];

            for ($i = 0; $i < $playable; $i++) {
                $pairings[] = [$ordered[$i * 2], $ordered[$i * 2 + 1]];
            }

            return $pairings;
        }

        // Team Americano: the team that has played least takes the court
        // first, against the available team it has met the fewest times.
        // Without this, teams level on matches played were paired in
        // roster order and met the same opponent every round.
        $meetings = $this->teamMeetings($session);
        $teamId = fn (array $members) => $members[0]->session_team_id;

        $pairings = [];
        $remaining = $ordered;

        while (count($pairings) < $playable && count($remaining) >= 2) {
            $home = array_shift($remaining);

            $bestIndex = 0;
            $fewest = PHP_INT_MAX;

            foreach ($remaining as $index => $candidate) {
                $met = $meetings[$this->meetingKey($teamId($home), $teamId($candidate))] ?? 0;

                // Strict <, so a tie keeps the candidate that has played least.
                if ($met < $fewest) {
                    $fewest = $met;
                    $bestIndex = $index;
                }
            }

            [$away] = array_splice($remaining, $bestIndex, 1);
            $pairings[] = [$home, $away];
        }

        return $pairings;
    }

    /**
     * How many times each pair of fixed teams has already met this session.
     *
     * @return array<string, int> meetingKey => count
     */
    private function teamMeetings(Session $session): array
    {
        $teamOf = SessionParticipant::query()
            ->where('session_id', $session->id)
            ->whereNotNull('session_team_id')
            ->pluck('session_team_id', 'id');

        $matches = MatchGame::query()
            ->whereHas('round', fn ($q) => $q->where('session_id', $session->id))
            ->with('teams.teamPlayers')
            ->get();

        $meetings = [];

        foreach ($matches as $match) {
            $teamIds = $match->teams
                ->map(fn ($team) => $teamOf[$team->teamPlayers->first()?->participant_id] ?? null)
                ->filter()
                ->unique()
                ->values();

            if ($teamIds->count() !== 2) {
                continue;
            }

            $key = $this->meetingKey($teamIds[0], $teamIds[1]);
            $meetings[$key] = ($meetings[$key] ?? 0) + 1;
        }

        return $meetings;
    }

    private function meetingKey(string $a, string $b): string
    {
        return $a < $b ? "{$a}|{$b}" : "{$b}|{$a}";
    }

    /**
     * Reads the session's fixed teams, creating them from the roster on
     * first use. Players already assigned keep their partner.
     *
     * @param  Collection<int, SessionParticipant>  $roster
     * @return list<list<SessionParticipant>>
     */
    private function ensureTeams(Session $session, $roster): array
    {
        $byTeam = $roster
            ->filter(fn ($p) => $p->session_team_id !== null)
            ->groupBy('session_team_id');

        $unassigned = $roster->filter(fn ($p) => $p->session_team_id === null)->values();

        // Pair up anyone not yet on a team, in roster order.
        $nextNumber = (int) SessionTeam::query()
            ->where('session_id', $session->id)
            ->max('team_number');

        for ($i = 0; $i + 1 < $unassigned->count(); $i += 2) {
            $a = $unassigned[$i];
            $b = $unassigned[$i + 1];

            $team = SessionTeam::query()->create([
                'session_id' => $session->id,
                'team_number' => ++$nextNumber,
                'name' => $a->display_name_snapshot.' / '.$b->display_name_snapshot,
            ]);

            foreach ([$a, $b] as $participant) {
                $participant->forceFill(['session_team_id' => $team->id])->save();
            }

            $byTeam[$team->id] = collect([$a, $b]);
        }

        // Only complete pairs can play; a leftover player sits out.
        return $byTeam
            ->filter(fn ($members) => $members->count() === 2)
            ->map(fn ($members) => $members->values()->all())
            ->values()
            ->all();
    }

    /**
     * Mixed formats (mix_americano, mixicano): every pair must be one man
     * and one woman.
     *
     * With $byRank (Mixicano) the four players on a court are balanced by
     * standings as well as gender.
     *
     * @param  Collection<int, SessionParticipant>  $roster
     * @return list<array{0: list<SessionParticipant>, 1: list<SessionParticipant>}>
     */
    private function mixedPairings($roster, int $courtCount, bool $byRank = false): array
    {
        $men = $roster->filter(fn ($p) => $p->player?->gender === PlayerGender::Male)->values();
        $women = $roster->filter(fn ($p) => $p->player?->gender === PlayerGender::Female)->values();

        $unknown = $roster->count() - $men->count() - $women->count();

        if ($unknown > 0) {
            throw new RuntimeException(
                "A mixed format needs every player's gender set; {$unknown} player(s) have not set theirs."
            );
        }

        // Each court needs 2 men and 2 women.
        $courts = min($courtCount, intdiv($men->count(), 2), intdiv($women->count(), 2));

        if ($courts < 1) {
            throw new RuntimeException(
                'A mixed format needs at least 2 men and 2 women to fill a court.'
            );
        }

        $pairings = [];

        for ($i = 0; $i < $courts; $i++) {
            $m1 = $men[$i * 2];
            $m2 = $men[$i * 2 + 1];
            $w1 = $women[$i * 2];
            $w2 = $women[$i * 2 + 1];

            // Mixicano: the roster is already in rank order, so the higher
            // man partners the lower woman of the four (and vice versa),
            // mirroring Mexicano's 1+4 v 2+3 balancing.
            $pairings[] = $byRank
                ? [[$m1, $w2], [$m2, $w1]]
                : [[$m1, $w1], [$m2, $w2]];
        }

        return $pairings;
    }

    /**
     * King of the Court.
     *
     * Court 1 is the king court. After each round the winning pair moves
     * one court up (toward court 1) and the losing pair moves one down.
     * Winners already on court 1 stay and defend; losers on the lowest
     * court stay. Anyone waiting in session_queue rotates in ahead of
     * players who have just played, so a big roster still gets court time.
     *
     * Round 1 has no results to promote from, so courts are seeded in
     * roster order.
     *
     * @param  Collection<int, SessionParticipant>  $roster
     * @param  Collection<int, Court>  $courts
     * @return list<array{0: list<SessionParticipant>, 1: list<SessionParticipant>}>
     */
    private function kothPairings(
        Session $session,
        $roster,
        $courts,
        bool $teamBased = false,
    ): array {
        $byId = $roster->keyBy('id');
        $playableCourts = min($courts->count(), intdiv($roster->count(), 4));

        if ($playableCourts < 1) {
            throw new RuntimeException('King of the Court needs at least 4 active players.');
        }

        if ($teamBased) {
            // Persist the partnerships, exactly as the other team formats
            // do, so a pair stays together as it moves between courts.
            $this->ensureTeams($session, $roster);
            $roster = $roster->map(fn ($p) => $p->refresh());
            $byId = $roster->keyBy('id');
        }

        $previous = $session->rounds()
            ->where('status', RoundStatus::Completed->value)
            ->orderByDesc('round_number')
            ->with(['matches.court', 'matches.teams.teamPlayers'])
            ->first();

        // Court number => the four participant ids that should play there.
        $seated = $previous
            ? $this->kothPromote($previous, $byId, $playableCourts)
            : [];

        $assigned = collect($seated)->flatten()->filter()->unique()->values();

        // Everyone not seated by promotion, queue first (longest wait), then
        // players who did not appear in the last round at all.
        $bench = $this->kothBench($session, $roster, $assigned->all());

        // Promotion can fill every slot, which would leave the same players
        // waiting forever. Give the queue the lowest court's places, taking
        // from the bottom up so the king court is never disturbed.
        //
        // Team KOTH steps aside in whole pairs: splitting a fixed
        // partnership to seat one queued player would break the format.
        if ($bench->isNotEmpty()) {
            $step = $teamBased ? 2 : 1;
            $waiting = intdiv($bench->count(), $step) * $step;

            for ($court = $playableCourts; $court >= 1 && $waiting > 0; $court--) {
                while ($waiting >= $step && count($seated[$court] ?? []) >= $step) {
                    for ($i = 0; $i < $step; $i++) {
                        $dropped = array_pop($seated[$court]);
                        $bench->push($byId[$dropped]);
                    }
                    $waiting -= $step;
                }
            }
        }

        // Fill any court that promotion left short. For team formats take
        // partners together, so a court always holds two complete pairs.
        for ($court = 1; $court <= $playableCourts; $court++) {
            $seated[$court] ??= [];

            while (count($seated[$court]) < 4 && $bench->isNotEmpty()) {
                $next = $bench->shift();
                $seated[$court][] = $next->id;

                if (! $teamBased || $next->session_team_id === null) {
                    continue;
                }

                // Pull this player's partner in alongside them.
                $partner = $bench->first(
                    fn ($p) => $p->session_team_id === $next->session_team_id
                );

                if ($partner && count($seated[$court]) < 4) {
                    $bench = $bench->reject(fn ($p) => $p->id === $partner->id)->values();
                    $seated[$court][] = $partner->id;
                }
            }
        }

        $this->syncKothQueue($session, $bench);

        $pairings = [];

        for ($court = 1; $court <= $playableCourts; $court++) {
            $four = collect($seated[$court] ?? [])
                ->map(fn ($id) => $byId[$id] ?? null)
                ->filter()
                ->values();

            if ($four->count() < 4) {
                // Not enough players left to fill this court.
                break;
            }

            // Team KOTH keeps its fixed pairs; otherwise partners rotate so
            // the promoted pair is split across the new court.
            $pairings[] = $teamBased
                ? [[$four[0], $four[1]], [$four[2], $four[3]]]
                : [[$four[0], $four[3]], [$four[1], $four[2]]];
        }

        return $pairings;
    }

    /**
     * Move winners up a court and losers down, from a completed round.
     *
     * @param  Collection<string, SessionParticipant>  $byId
     * @return array<int, list<string>> court number => participant ids
     */
    private function kothPromote(Round $previous, $byId, int $playableCourts): array
    {
        $seated = [];

        foreach ($previous->matches as $match) {
            $courtNumber = $match->court?->court_number;

            if ($courtNumber === null) {
                continue;
            }

            foreach ($match->teams as $team) {
                $ids = $team->teamPlayers
                    ->pluck('participant_id')
                    ->filter(fn ($id) => $byId->has($id))
                    ->values()
                    ->all();

                if ($ids === []) {
                    continue;
                }

                $won = $match->winner_team !== null && $team->side === $match->winner_team;

                // Court 1 is the top: winners move toward it, losers away.
                // Nobody moves past either end.
                $target = $won
                    ? max(1, $courtNumber - 1)
                    : min($playableCourts, $courtNumber + 1);

                $seated[$target] = array_merge($seated[$target] ?? [], $ids);
            }
        }

        // A court can only seat four; the overflow goes back to the bench.
        foreach ($seated as $court => $ids) {
            $seated[$court] = array_slice($ids, 0, 4);
        }

        ksort($seated);

        return $seated;
    }

    /**
     * Players available to fill empty slots: queued players first (by
     * position, longest wait), then anyone else not already seated.
     *
     * @param  Collection<int, SessionParticipant>  $roster
     * @param  list<string>  $assignedIds
     * @return Collection<int, SessionParticipant>
     */
    private function kothBench(Session $session, $roster, array $assignedIds)
    {
        $assigned = array_flip($assignedIds);

        $queuedIds = SessionQueue::query()
            ->where('session_id', $session->id)
            ->whereNull('dequeued_at')
            ->orderBy('position')
            ->pluck('participant_id')
            ->all();

        $available = $roster->reject(fn ($p) => isset($assigned[$p->id]));

        $queued = collect($queuedIds)
            ->map(fn ($id) => $available->firstWhere('id', $id))
            ->filter();

        $queuedFlip = array_flip($queued->pluck('id')->all());

        // Queued players first, then the rest in roster order.
        return $queued
            ->concat($available->reject(fn ($p) => isset($queuedFlip[$p->id])))
            ->values();
    }

    /**
     * Rewrite session_queue to exactly the players sitting out this round,
     * so the client's waiting list reflects reality.
     *
     * @param  Collection<int, SessionParticipant>  $bench
     */
    private function syncKothQueue(Session $session, $bench): void
    {
        // session_queue_position_unique covers (session_id, position) and
        // ignores dequeued_at, so superseded rows would block reusing
        // position 1. The queue is rebuilt wholesale each round, so clear
        // it rather than tombstoning.
        SessionQueue::query()
            ->where('session_id', $session->id)
            ->delete();

        foreach ($bench->values() as $index => $participant) {
            SessionQueue::query()->create([
                'session_id' => $session->id,
                'participant_id' => $participant->id,
                'position' => $index + 1,
                'enqueued_at' => now(),
            ]);
        }
    }

    /**
     * Mexicano: opponents come from the standings, not from evenness.
     *
     * The roster arrives in rank order, so each court takes the next four
     * players and pairs them 1st+4th against 2nd+3rd. That puts the
     * leader with the weakest of the four, which is the whole point of
     * the format — it keeps matches close.
     *
     * In round 1 nobody is ranked yet, so this falls back to roster order,
     * which is the conventional seeding.
     *
     * @param  Collection<int, SessionParticipant>  $roster
     * @return list<array{0: list<SessionParticipant>, 1: list<SessionParticipant>}>
     */
    private function mexicanoPairings(Session $session, $roster, int $courtCount): array
    {
        $pairings = [];
        $playable = min($courtCount, intdiv($roster->count(), 4));

        for ($i = 0; $i < $playable; $i++) {
            $four = $roster->slice($i * 4, 4)->values();

            // [0]=best of the four, [3]=weakest.
            $pairings[] = [
                [$four[0], $four[3]],
                [$four[1], $four[2]],
            ];
        }

        return $pairings;
    }

    /**
     * Individual formats with no ranking rule (americano, koth): partners
     * rotate every round, paired [1,3] v [2,4] from the fairness-sorted
     * roster. Mexicano uses mexicanoPairings() instead.
     *
     * @param  Collection<int, SessionParticipant>  $roster
     * @return list<array{0: list<SessionParticipant>, 1: list<SessionParticipant>}>
     */
    private function rotationPairings($roster, int $courtCount): array
    {
        $pairings = [];
        $playable = min($courtCount, intdiv($roster->count(), 4));

        for ($i = 0; $i < $playable; $i++) {
            $four = $roster->slice($i * 4, 4)->values();
            $pairings[] = [[$four[0], $four[2]], [$four[1], $four[3]]];
        }

        return $pairings;
    }
}
