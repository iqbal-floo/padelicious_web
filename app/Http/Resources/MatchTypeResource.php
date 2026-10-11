<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'category' => $this->category,
            'is_team_based' => $this->is_team_based,
            'is_mixed' => $this->is_mixed,
            'requires_club' => $this->requires_club,
            'sort_order' => $this->sort_order,
        ];
    }
}
