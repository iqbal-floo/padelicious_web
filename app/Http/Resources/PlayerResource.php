<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'reclub_username' => $this->reclub_username,
            'display_name' => $this->display_name,
            'initials' => $this->initials,
            'avatar_url' => $this->avatar_url,
            'avatar_color_index' => $this->avatar_color_index,
            'skill_level' => $this->skill_level,
            'skill_level_label' => $this->skill_level_label,
            'gender' => $this->gender?->value,
            'is_guest' => (bool) $this->is_guest,
            'stats' => $this->whenLoaded('stats', fn () => [
                'sessions_played' => $this->stats->sessions_played,
                'matches_played' => $this->stats->matches_played,
                'matches_won' => $this->stats->matches_won,
                'matches_lost' => $this->stats->matches_lost,
                'total_points' => $this->stats->total_points,
                'win_rate' => $this->stats->win_rate,
                'last_played_at' => $this->stats->last_played_at?->toIso8601String(),
            ]),
            'clubs' => ClubResource::collection($this->whenLoaded('clubs')),
        ];
    }
}
