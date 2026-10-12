<?php

namespace App\Services;

use App\Enums\MatchStatus;
use App\Enums\PlayerGender;
use App\Enums\TeamSide;
use App\Models\MatchTeam;
use App\Models\Session;
use App\Models\SessionParticipant;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The printable order of play for a session: one row per match in round
 * then court order — the sheet a host pins up at the venue. Works for
 * every match type, because all of them produce rounds, courts and
 * two-sided matches.
 */
class OrderOfPlay
{
    /**
     * @param  ?string  $startTime  "08:15" — adds a time column, one slot per round.
     * @return array{session: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function build(Session $session, ?string $startTime = null, int $slotMinutes = 15): array
    {
        $session->loadMissing('matchType');
        $sessionFormat = MatchFormat::fromSession($session);
        $formats = [];

        $rounds = $session->rounds()
            ->with(['matches.court', 'matches.teams.players.player'])
            ->orderBy('round_number')
            ->get();

        $start = $startTime ? Carbon::createFromFormat('H:i', $startTime) : null;

        $rows = [];
        $no = 0;

        foreach ($rounds as $round) {
            $matches = $round->matches->sortBy(fn ($m) => $m->court?->court_number ?? PHP_INT_MAX);

            foreach ($matches as $match) {
                $no++;

                $sideA = $match->teams->first(fn ($t) => $t->side === TeamSide::A);
                $sideB = $match->teams->first(fn ($t) => $t->side === TeamSide::B);

                $finished = $match->status === MatchStatus::Finished;
                $started = $finished || $match->status === MatchStatus::Live;

                // A match may play to its own rule (men to 5, women to 4).
                $format = $match->tennis_mode
                    ? MatchFormat::fromTennisMode($match->tennis_mode)
                    : $sessionFormat;
                $formats[$format->label()] = true;

                $rows[] = [
                    'no' => $no,
                    'round' => $round->round_number,
                    'time' => $start?->copy()
                        ->addMinutes(($round->round_number - 1) * $slotMinutes)
                        ->format('H:i'),
                    'court' => $match->court?->court_number,
                    'court_label' => $match->court?->label,
                    'category' => $this->category($match->teams->flatMap(fn ($t) => $t->players)),
                    'team_a' => $this->names($sideA),
                    'team_b' => $this->names($sideB),
                    // Blank until the match starts, so a fresh sheet prints
                    // empty score boxes.
                    'games_a' => $started ? $match->games_a : null,
                    'games_b' => $started ? $match->games_b : null,
                    'status' => $match->status?->value,
                    'winner' => $match->winner_team?->value,
                    // This format can finish level, decided by one golden point.
                    'format' => $format->label(),
                    'golden_point' => $format->canEndLevel(),
                    'decided_by_golden_point' => $finished && $match->games_a === $match->games_b,
                    'match_id' => $match->id,
                ];
            }
        }

        return [
            'session' => [
                'id' => $session->id,
                'name' => $session->name,
                'scheduled_date' => $session->scheduled_date?->toDateString(),
                'match_type' => $session->matchType?->label,
                'court_count' => $session->court_count,
                'format' => $formats ? implode(' · ', array_keys($formats)) : $sessionFormat->label(),
                'golden_point' => (bool) $session->golden_point,
                'start_time' => $startTime,
                'slot_minutes' => $slotMinutes,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * "Dikdoyo & Reyhan", in slot order.
     */
    private function names(?MatchTeam $team): string
    {
        if (! $team) {
            return '';
        }

        return $team->players
            ->sortBy('pivot.slot')
            ->pluck('display_name_snapshot')
            ->implode(' & ');
    }

    /**
     * M = all men, W = all women, Mix = both, – = not known.
     *
     * @param  Collection<int, SessionParticipant>  $players
     */
    private function category(Collection $players): string
    {
        $genders = $players
            ->map(fn ($p) => $p->player?->gender)
            ->filter(fn ($g) => $g === PlayerGender::Male || $g === PlayerGender::Female)
            ->unique();

        return match (true) {
            $genders->count() === 2 => 'Mix',
            $genders->contains(PlayerGender::Male) => 'M',
            $genders->contains(PlayerGender::Female) => 'W',
            default => '–',
        };
    }
}
