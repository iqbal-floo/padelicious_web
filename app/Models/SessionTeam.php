<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A fixed partnership for the whole session (team_* formats).
 */
class SessionTeam extends Model
{
    use HasUuids;

    protected $table = 'session_teams';

    protected $fillable = ['session_id', 'name', 'team_number'];

    protected function casts(): array
    {
        return ['team_number' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SessionParticipant::class, 'session_team_id');
    }
}
