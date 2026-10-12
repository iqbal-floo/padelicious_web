<?php

namespace App\Http\Requests;

use App\Enums\PlayerGender;
use App\Models\Player;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Names may repeat; the username is the unique handle.
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'username' => ['required', 'string', ...Player::USERNAME_RULES, 'unique:players,username'],
            // citext column, so uniqueness is already case-insensitive.
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            // The client asks for "at least 6 characters"; keep them aligned.
            'password' => ['required', 'confirmed', Password::min(6)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Optional at sign-up; required later to join a mixed format.
            'gender' => ['sometimes', 'nullable', Rule::enum(PlayerGender::class)],
            'device_name' => ['sometimes', 'string', 'max:255'],
            'accepted_terms' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists.',
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
