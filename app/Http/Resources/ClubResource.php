<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClubResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'emoji' => $this->emoji,
            'theme_color' => $this->theme_color,
            'description' => $this->description,
            'city' => $this->city,
            'visibility' => $this->visibility?->value,
            'member_count' => $this->member_count,
            'owner' => new PlayerResource($this->whenLoaded('owner')),
            'members' => PlayerResource::collection($this->whenLoaded('members')),
            // Present only when the request is authenticated and membership was resolved.
            'is_member' => $this->when(isset($this->is_member), fn () => (bool) $this->is_member),
        ];
    }
}
