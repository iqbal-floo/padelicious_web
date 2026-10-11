<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Composite-key table (session_id, participant_id).
 */
class SessionStanding extends Model
{
    protected $table = 'session_standings';

    public $incrementing = false;

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    /**
     * win_rate is a stored generated column; never write to it.
     */
    protected $guarded = ['win_rate'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'wins' => 'integer',
            'losses' => 'integer',
            'draws' => 'integer',
            'matches_played' => 'integer',
            'games_won' => 'integer',
            'games_lost' => 'integer',
            'win_rate' => 'float',
            'rank' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(SessionParticipant::class, 'participant_id');
    }
}
