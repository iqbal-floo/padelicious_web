<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantSource;
use App\Enums\ParticipantStatus;
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

            $roster = $session->participants()
                ->where('status', ParticipantStatus::Active->value)
                ->get()
                ->sortBy([
                    fn ($p) => $standings[$p->id]->matches_played ?? 0,
                    fn ($p) => -($standings[$p->id]->points ?? 0),
                ])
                ->values();

            if ($roster->count() < 4) {
                throw new RuntimeException('At least 4 active players are required.');
            }

            $playableCourts = min($courts->count(), intdiv($roster->count(), 4));

            $round = Round::query()->create([
                'session_id' => $session->id,
                'round_number' => (int) $session->rounds()->max('round_number') + 1,
                'status' => RoundStatus::InProgress,
                'generated_at' => now(),
            ]);

            for ($i = 0; $i < $playableCourts; $i++) {
                $four = $roster->slice($i * 4, 4)->values();

                $match = MatchGame::query()->create([
                    'round_id' => $round->id,
                    'court_id' => $courts[$i]->id,
                    'status' => MatchStatus::Scheduled,
                    'serving_team' => TeamSide::A,
                ]);

                foreach ([[TeamSide::A, [0, 2]], [TeamSide::B, [1, 3]]] as [$side, $slots]) {
                    $team = MatchTeam::query()->create([
                        'match_id' => $match->id,
                        'side' => $side,
                    ]);

                    foreach ($slots as $slot => $rosterIndex) {
                        MatchTeamPlayer::query()->create([
                            'match_team_id' => $team->id,
                            'participant_id' => $four[$rosterIndex]->id,
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
}
