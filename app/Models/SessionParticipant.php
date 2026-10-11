<?php

namespace App\Models;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantSource;
use App\Enums\ParticipantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SessionParticipant extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'session_id', 'player_id', 'display_name_snapshot',
        'initials_snapshot', 'avatar_color_index_snapshot',
        'role', 'source', 'status', 'joined_at', 'session_team_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'source' => ParticipantSource::class,
            'status' => ParticipantStatus::class,
            'avatar_color_index_snapshot' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * The fixed partnership this player belongs to, for team_* formats.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(SessionTeam::class, 'session_team_id');
    }

    public function standing(): HasOne
    {
        return $this->hasOne(SessionStanding::class, 'participant_id');
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(SessionQueue::class, 'participant_id');
    }
}
