<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchSubstitution extends Model
{
    use HasUuids;

    protected $table = 'match_substitutions';

    public $timestamps = false;

    protected $fillable = [
        'match_id', 'match_team_id', 'slot',
        'out_participant_id', 'in_participant_id', 'at_sequence', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'at_sequence' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchGame::class, 'match_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(MatchTeam::class, 'match_team_id');
    }

    public function outParticipant(): BelongsTo
    {
        return $this->belongsTo(SessionParticipant::class, 'out_participant_id');
    }

    public function inParticipant(): BelongsTo
    {
        return $this->belongsTo(SessionParticipant::class, 'in_participant_id');
    }
}
