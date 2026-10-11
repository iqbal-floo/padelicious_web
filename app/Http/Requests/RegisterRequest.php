<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            // citext column, so uniqueness is already case-insensitive.
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            // The client asks for "at least 6 characters"; keep them aligned.
            'password' => ['required', 'confirmed', Password::min(6)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
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
        ];
    }
}
