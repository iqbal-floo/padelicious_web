<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantSource;
use App\Enums\ParticipantStatus;
use App\Enums\PlayerGender;
use App\Enums\RoundStatus;
use App\Enums\SessionStatus;
use App\Enums\TeamSide;
use App\Models\Court;
use App\Models\MatchGame;
use App\Models\MatchTeam;
use App\Models\MatchTeamPlayer;
use App\Models\Player;
use App\Models\Round;
use App\Models\Session;
use App\Models\SessionParticipant;
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
                $this->addParticipant(
                    $session,
                    $entry['player_id'],
                    $entry['role'] ?? ParticipantRole::Player,
                    isset($entry['source'])
                        ? ParticipantSource::from($entry['source'])
                        : ParticipantSource::Manual,
                );
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
    ): SessionParticipant {
        $player = Player::query()->findOrFail($playerId);

        $existing = SessionParticipant::query()
            ->where('session_id', $session->id)
            ->where('player_id', $playerId)
            ->first();

        if ($existing) {
            // Re-joining after withdrawing reactivates the same row.
            if ($existing->status === ParticipantStatus::Withdrawn) {
                $existing->forceFill(['status' => ParticipantStatus::Active])->save();

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
            'status' => ParticipantStatus::Active,
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
                ->where('status', ParticipantStatus::Active->value)
                // player is needed for the mixed-gender constraint.
                ->with('player')
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
            // evenness, so it needs its own strategies.
            $isMexicano = $matchType?->category === 'Mexicano';

            $pairings = match (true) {
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
                $match = MatchGame::query()->create([
                    'round_id' => $round->id,
                    'court_id' => $courts[$i]->id,
                    'status' => MatchStatus::Scheduled,
                    'serving_team' => TeamSide::A,
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
            }

            if ($session->status === SessionStatus::Draft) {
                $session->forceFill([
                    'status' => SessionStatus::Active,
                    'started_at' => $session->started_at ?? now(),
                ])->save();
            }

            return $round->load('matches.teams.players');
        });
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

        $pairings = [];
        $playable = min($courtCount, intdiv(count($ordered), 2));

        for ($i = 0; $i < $playable; $i++) {
            $pairings[] = [$ordered[$i * 2], $ordered[$i * 2 + 1]];
        }

        return $pairings;
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
