<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status?->value,
            'games_a' => $this->games_a,
            'games_b' => $this->games_b,
            'points_a' => $this->points_a,
            'points_b' => $this->points_b,
            'serving_team' => $this->serving_team?->value,
            'winner_team' => $this->winner_team?->value,
            'tennis_mode' => $this->tennis_mode?->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'court' => $this->whenLoaded('court', fn () => [
                'id' => $this->court->id,
                'court_number' => $this->court->court_number,
                'label' => $this->court->label,
            ]),
            'teams' => $this->whenLoaded('teams', fn () => $this->teams->map(fn ($team) => [
                'id' => $team->id,
                'side' => $team->side?->value,
                'score_points' => $team->score_points,
                'players' => $team->relationLoaded('players')
                    ? $team->players->map(fn ($p) => [
                        'participant_id' => $p->id,
                        'display_name' => $p->display_name_snapshot,
                        'initials' => $p->initials_snapshot,
                        'avatar_color_index' => $p->avatar_color_index_snapshot,
                        'slot' => $p->pivot->slot,
                    ])->values()
                    : [],
            ])->values()),
        ];
    }
}
