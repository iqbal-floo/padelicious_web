<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'round_number' => $this->round_number,
            'status' => $this->status?->value,
            'generated_at' => $this->generated_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'matches' => MatchResource::collection($this->whenLoaded('matches')),
        ];
    }
}
