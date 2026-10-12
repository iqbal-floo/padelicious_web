<?php

namespace App\Models;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantSource;
use App\Enums\ParticipantStatus;
use App\Enums\ParticipantTag;
use Illuminate\Database\Eloquent\Builder;
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
        'role', 'source', 'status', 'joined_at', 'session_team_id', 'tags',
    ];

    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'source' => ParticipantSource::class,
            'status' => ParticipantStatus::class,
            'tags' => 'array',
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

    public function hasTag(ParticipantTag $tag): bool
    {
        return in_array($tag->value, $this->tags ?? [], true);
    }

    /**
     * Players who can be put into a match: confirmed and not marked
     * no-show. Waitlisted, requested and withdrawn players sit out.
     */
    public function scopeEligible(Builder $query): Builder
    {
        return $query
            ->where('status', ParticipantStatus::Confirmed->value)
            // @> rather than ?, which PDO would read as a placeholder.
            ->whereRaw('NOT (tags @> ?::jsonb)', [json_encode([ParticipantTag::NoShow->value])]);
    }
}
