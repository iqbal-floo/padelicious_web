<?php

namespace App\Http\Requests;

use App\Enums\PlayerGender;
use App\Models\Player;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /players — a guest player added by hand, with no account.
 */
class StorePlayerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Names may repeat; the username is the unique handle.
            'display_name' => ['required', 'string', 'min:1', 'max:60'],
            'username' => ['required', 'string', ...Player::USERNAME_RULES, 'unique:players,username'],
            'initials' => ['sometimes', 'nullable', 'string', 'size:2'],
            'gender' => ['sometimes', 'nullable', Rule::enum(PlayerGender::class)],
            'skill_level' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:5'],
            'skill_level_label' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.unique' => 'This username is already taken.',
            'username.regex' => 'Usernames use lowercase letters, digits, dots and underscores (2–30 characters).',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge(['username' => mb_strtolower(trim((string) $this->input('username')))]);
        }
    }
}
