<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StandingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'participant_id' => $this->participant_id,
            'rank' => $this->rank,
            'points' => $this->points,
            'wins' => $this->wins,
            'losses' => $this->losses,
            'draws' => $this->draws,
            'matches_played' => $this->matches_played,
            'games_won' => $this->games_won,
            'games_lost' => $this->games_lost,
            'win_rate' => $this->win_rate,
            'participant' => new SessionParticipantResource($this->whenLoaded('participant')),
        ];
    }
}
