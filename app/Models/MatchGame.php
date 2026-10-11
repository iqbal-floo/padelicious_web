<?php

namespace App\Models;

use App\Enums\MatchStatus;
use App\Enums\TeamSide;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The "matches" table. Named MatchGame because `match` is a reserved
 * keyword in PHP 8, so a class named Match is not allowed.
 */
class MatchGame extends Model
{
    use HasUuids;

    protected $table = 'matches';

    protected $fillable = [
        'round_id', 'court_id', 'status', 'games_a', 'games_b',
        'points_a', 'points_b', 'serving_team', 'winner_team',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MatchStatus::class,
            'serving_team' => TeamSide::class,
            'winner_team' => TeamSide::class,
            'games_a' => 'integer',
            'games_b' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function court(): BelongsTo
    {
        return $this->belongsTo(Court::class);
    }

    public function teams(): HasMany
    {
        return $this->hasMany(MatchTeam::class, 'match_id');
    }

    public function points(): HasMany
    {
        return $this->hasMany(MatchPoint::class, 'match_id');
    }

    public function substitutions(): HasMany
    {
        return $this->hasMany(MatchSubstitution::class, 'match_id');
    }
}
