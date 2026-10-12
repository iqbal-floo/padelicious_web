<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'icon' => $this->icon,
            'status' => $this->status?->value,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'court_count' => $this->court_count,
            'scoring_type' => $this->scoring_type?->value,
            'points_target' => $this->points_target,
            'tennis_mode' => $this->tennis_mode?->value,
            'golden_point' => $this->golden_point,
            'leaderboard_sort' => $this->leaderboard_sort?->value,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'order_of_play_url' => route('sessions.order-of-play', $this->resource),
            'match_type' => new MatchTypeResource($this->whenLoaded('matchType')),
            'club' => new ClubResource($this->whenLoaded('club')),
            'host' => new PlayerResource($this->whenLoaded('host')),
            'participants' => SessionParticipantResource::collection($this->whenLoaded('participants')),
            // What the signed-in user may do here, so the app can show or
            // hide host-only actions.
            'can_manage' => (bool) $request->user()?->can('manage', $this->resource),
            'my_participant' => $this->whenLoaded('participants', function () use ($request) {
                $mine = $this->participants->firstWhere('player_id', $request->user()?->player?->id);

                return $mine ? ['id' => $mine->id, 'status' => $mine->status?->value] : null;
            }),
            'participant_count' => $this->when(
                isset($this->participant_count),
                fn () => (int) $this->participant_count
            ),
        ];
    }
}
