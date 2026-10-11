<?php

namespace App\Http\Requests;

use App\Enums\TeamSide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordPointRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'team' => ['required', Rule::enum(TeamSide::class)],
        ];
    }
}
