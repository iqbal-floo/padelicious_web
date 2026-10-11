<?php

namespace App\Http\Requests;

use App\Enums\PlayerGender;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],

            // Player profile fields.
            'display_name' => ['sometimes', 'string', 'min:1', 'max:60'],
            'gender' => ['sometimes', Rule::enum(PlayerGender::class)],
            'skill_level' => ['sometimes', 'nullable', 'numeric', 'between:1,5'],
            'skill_level_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'avatar_color_index' => ['sometimes', 'integer', 'between:0,7'],
        ];
    }
}
