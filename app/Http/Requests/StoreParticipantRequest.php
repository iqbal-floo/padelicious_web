<?php

namespace App\Http\Requests;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreParticipantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'player_id' => ['required', 'uuid', 'exists:players,id'],
            'role' => ['sometimes', Rule::enum(ParticipantRole::class)],
            'source' => ['sometimes', Rule::enum(ParticipantSource::class)],
        ];
    }
}
