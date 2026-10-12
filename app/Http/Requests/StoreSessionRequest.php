<?php

namespace App\Http\Requests;

use App\Enums\LeaderboardSort;
use App\Enums\ScoringType;
use App\Enums\TennisMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSessionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'icon' => ['sometimes', 'string', 'max:8'],
            'match_type_code' => ['required', 'string', 'exists:match_types,code'],
            'scheduled_date' => ['required', 'date'],
            'court_count' => ['required', 'integer', 'min:1', 'max:4'],
            'club_id' => ['nullable', 'uuid', 'exists:clubs,id'],

            'scoring_type' => ['required', Rule::enum(ScoringType::class)],
            // Only one of these may be set; see the schema's
            // sessions_scoring_consistent CHECK constraint.
            'points_target' => ['nullable', 'integer', Rule::in([16, 21, 24, 32])],
            'tennis_mode' => ['nullable', Rule::enum(TennisMode::class)],

            'golden_point' => ['sometimes', 'boolean'],
            'leaderboard_sort' => ['sometimes', Rule::enum(LeaderboardSort::class)],

            'participants' => ['sometimes', 'array', 'max:64'],
            'participants.*.player_id' => ['required', 'uuid', 'exists:players,id'],
            'participants.*.source' => ['sometimes', 'string', 'in:manual,club,reclub'],
            // Imported waitlists stay waitlisted; everyone else is confirmed.
            'participants.*.status' => ['sometimes', 'string', 'in:confirmed,waitlisted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('scoring_type');

            // Mirror the database CHECK so the client gets a 422 rather
            // than a constraint violation.
            if ($type === ScoringType::Normal->value && $this->filled('tennis_mode')) {
                $validator->errors()->add('tennis_mode', 'Normal scoring cannot set a tennis mode.');
            }

            if ($type === ScoringType::Tennis->value && $this->filled('points_target')) {
                $validator->errors()->add('points_target', 'Tennis scoring cannot set a points target.');
            }
        });
    }
}
