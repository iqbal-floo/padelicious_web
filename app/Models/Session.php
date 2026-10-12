<?php

namespace App\Models;

use App\Enums\LeaderboardSort;
use App\Enums\ScoringType;
use App\Enums\SessionStatus;
use App\Enums\TennisMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A padel play session (docs/schema.sql "sessions"), NOT an HTTP session.
 * The framework's session store is file-based so it does not use this table.
 */
class Session extends Model
{
    use HasUuids;

    protected $table = 'sessions';

    protected $fillable = [
        'club_id', 'host_player_id', 'name', 'icon', 'match_type_code',
        'scheduled_date', 'court_count', 'scoring_type', 'points_target',
        'tennis_mode', 'golden_point', 'leaderboard_sort', 'status',
        'started_at', 'completed_at', 'continuous_play',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'court_count' => 'integer',
            'points_target' => 'integer',
            'scoring_type' => ScoringType::class,
            'tennis_mode' => TennisMode::class,
            'leaderboard_sort' => LeaderboardSort::class,
            'status' => SessionStatus::class,
            'golden_point' => 'boolean',
            'continuous_play' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'host_player_id');
    }

    public function matchType(): BelongsTo
    {
        return $this->belongsTo(MatchType::class, 'match_type_code', 'code');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(SessionParticipant::class);
    }

    public function courts(): HasMany
    {
        return $this->hasMany(Court::class);
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    public function queue(): HasMany
    {
        return $this->hasMany(SessionQueue::class);
    }

    public function standings(): HasMany
    {
        return $this->hasMany(SessionStanding::class);
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(ShareLink::class);
    }

    public function reclubImports(): HasMany
    {
        return $this->hasMany(ReclubImport::class);
    }
}
