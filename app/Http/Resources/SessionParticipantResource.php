<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionParticipantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'player_id' => $this->player_id,
            // Snapshots preserve how the player appeared when they joined.
            'display_name' => $this->display_name_snapshot,
            'initials' => $this->initials_snapshot,
            'avatar_color_index' => $this->avatar_color_index_snapshot,
            'role' => $this->role?->value,
            'source' => $this->source?->value,
            'status' => $this->status?->value,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'player' => new PlayerResource($this->whenLoaded('player')),
        ];
    }
}
