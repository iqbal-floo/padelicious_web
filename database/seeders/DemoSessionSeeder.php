<?php

namespace Database\Seeders;

use App\Enums\ClubRole;
use App\Enums\MatchStatus;
use App\Enums\ParticipantRole;
use App\Enums\ParticipantSource;
use App\Enums\RoundStatus;
use App\Enums\SessionStatus;
use App\Enums\TeamSide;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Court;
use App\Models\MatchGame;
use App\Models\MatchTeam;
use App\Models\MatchTeamPlayer;
use App\Models\Player;
use App\Models\PlayerStat;
use App\Models\Round;
use App\Models\Session;
use App\Models\SessionParticipant;
use App\Models\SessionStanding;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data mirroring the Flutter app's MockData, so the API returns
 * something recognisable while the client is being wired up.
 */
class DemoSessionSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $names = [
                ['Iqbal', 'IQ', 0], ['Rafi', 'RF', 1], ['Budi', 'BD', 2], ['Hendra', 'HN', 3],
                ['Andi', 'AN', 4], ['Dito', 'DT', 5], ['Yoga', 'YG', 6], ['Febri', 'FB', 7],
            ];

            $players = [];
            foreach ($names as [$name, $initials, $colorIndex]) {
                $player = Player::query()->create([
                    'display_name' => $name,
                    'initials' => $initials,
                    'avatar_color_index' => $colorIndex,
                    'skill_level' => 3.0 + ($colorIndex % 3) * 0.5,
                    'skill_level_label' => 'Intermediate',
                ]);
                PlayerStat::query()->create([
                    'player_id' => $player->id,
                    'sessions_played' => 12 + $colorIndex,
                    'matches_played' => 40 + $colorIndex * 2,
                    'matches_won' => 20 + $colorIndex,
                    'matches_lost' => 20 + $colorIndex,
                    'total_points' => 400 + $colorIndex * 10,
                    'last_played_at' => now()->subDays($colorIndex),
                ]);
                $players[] = $player;
            }

            // Link the existing admin user to the first player.
            if ($user = User::query()->where('email', 'iqbalpermana09@gmail.com')->first()) {
                $players[0]->update(['user_id' => $user->id]);
            }

            $club = Club::query()->create([
                'slug' => 'padelicious-jakarta',
                'name' => 'Padelicious Jakarta',
                'emoji' => '🎾',
                'description' => 'Komunitas padel Jakarta Selatan.',
                'city' => 'Jakarta',
                'owner_player_id' => $players[0]->id,
            ]);

            foreach ($players as $i => $player) {
                ClubMember::query()->create([
                    'club_id' => $club->id,
                    'player_id' => $player->id,
                    'role' => $i === 0 ? ClubRole::Owner : ClubRole::Member,
                ]);
            }

            $session = Session::query()->create([
                'club_id' => $club->id,
                'host_player_id' => $players[0]->id,
                'name' => 'Friday Night Americano',
                'icon' => '🎾',
                'match_type_code' => 'americano',
                'scheduled_date' => now()->toDateString(),
                'court_count' => 2,
                'points_target' => 24,
                'status' => SessionStatus::Active,
                'started_at' => now()->subHour(),
            ]);

            $participants = [];
            foreach ($players as $i => $player) {
                $participants[] = SessionParticipant::query()->create([
                    'session_id' => $session->id,
                    'player_id' => $player->id,
                    'display_name_snapshot' => $player->display_name,
                    'initials_snapshot' => $player->initials,
                    'avatar_color_index_snapshot' => $player->avatar_color_index,
                    'role' => $i === 0 ? ParticipantRole::Host : ParticipantRole::Player,
                    'tags' => $i === 0 ? ['host'] : [],
                    'source' => $i > 5 ? ParticipantSource::Reclub : ParticipantSource::Manual,
                ]);
            }

            $courts = [];
            foreach ([1, 2] as $number) {
                $courts[] = Court::query()->create([
                    'session_id' => $session->id,
                    'court_number' => $number,
                    'label' => "Court {$number}",
                ]);
            }

            $round = Round::query()->create([
                'session_id' => $session->id,
                'round_number' => 1,
                'status' => RoundStatus::InProgress,
            ]);

            // Court 1 live, court 2 finished.
            $specs = [
                [0, [0, 1], [2, 3], MatchStatus::Live, 2, 1, '40', '30', TeamSide::A, null],
                [1, [4, 5], [6, 7], MatchStatus::Finished, 3, 1, '0', '0', null, TeamSide::A],
            ];

            foreach ($specs as [$courtIdx, $sideA, $sideB, $status, $gamesA, $gamesB, $pointsA, $pointsB, $serving, $winner]) {
                $match = MatchGame::query()->create([
                    'round_id' => $round->id,
                    'court_id' => $courts[$courtIdx]->id,
                    'status' => $status,
                    'games_a' => $gamesA,
                    'games_b' => $gamesB,
                    'points_a' => $pointsA,
                    'points_b' => $pointsB,
                    'serving_team' => $serving,
                    'winner_team' => $winner,
                    'started_at' => now()->subMinutes(30),
                    'finished_at' => $status === MatchStatus::Finished ? now()->subMinutes(5) : null,
                ]);

                foreach ([[TeamSide::A, $sideA], [TeamSide::B, $sideB]] as [$side, $idxs]) {
                    $team = MatchTeam::query()->create([
                        'match_id' => $match->id,
                        'side' => $side,
                        'score_points' => $side === TeamSide::A ? $gamesA * 4 : $gamesB * 4,
                    ]);
                    foreach ($idxs as $slot => $pIdx) {
                        MatchTeamPlayer::query()->create([
                            'match_team_id' => $team->id,
                            'participant_id' => $participants[$pIdx]->id,
                            'slot' => $slot + 1,
                        ]);
                    }
                }
            }

            foreach ($participants as $i => $participant) {
                $wins = 8 - $i;
                $losses = 2 + $i;
                SessionStanding::query()->create([
                    'session_id' => $session->id,
                    'participant_id' => $participant->id,
                    'points' => 120 - $i * 8,
                    'wins' => $wins,
                    'losses' => $losses,
                    'draws' => 0,
                    'matches_played' => $wins + $losses,
                    'games_won' => 24 - $i,
                    'games_lost' => 10 + $i,
                    'rank' => $i + 1,
                ]);
            }
        });
    }
}
