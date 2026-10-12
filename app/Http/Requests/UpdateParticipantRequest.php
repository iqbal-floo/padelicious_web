<?php

namespace App\Http\Requests;

use App\Enums\ParticipantStatus;
use App\Enums\ParticipantTag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /sessions/{session}/participants/{participant}
 *
 * A host moves a player between confirmed and waitlisted, and sets tags.
 * `tags` replaces the whole list.
 */
class UpdateParticipantRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(ParticipantStatus::hostSettable())],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['distinct', Rule::enum(ParticipantTag::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.in' => 'A host can set a player to confirmed or waitlisted.',
        ];
    }
}
