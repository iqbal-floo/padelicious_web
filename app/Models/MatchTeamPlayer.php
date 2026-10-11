<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composite-key join table (match_team_id, slot).
 */
class MatchTeamPlayer extends Model
{
    protected $table = 'match_team_players';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['match_team_id', 'participant_id', 'slot'];

    protected function casts(): array
    {
        return ['slot' => 'integer'];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(MatchTeam::class, 'match_team_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(SessionParticipant::class, 'participant_id');
    }
}
