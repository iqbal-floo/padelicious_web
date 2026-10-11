<?php

namespace App\Models;

use App\Enums\TeamSide;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only scoring event log: the source of truth for undo and replay.
 */
class MatchPoint extends Model
{
    use HasUuids;

    protected $table = 'match_points';

    public $timestamps = false;

    protected $fillable = [
        'match_id', 'sequence', 'scoring_team', 'serving_team',
        'points_a_before', 'points_b_before', 'games_a_before', 'games_b_before',
        'is_game_point', 'is_golden_point', 'recorded_by_player_id',
        'recorded_at', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'scoring_team' => TeamSide::class,
            'serving_team' => TeamSide::class,
            'games_a_before' => 'integer',
            'games_b_before' => 'integer',
            'is_game_point' => 'boolean',
            'is_golden_point' => 'boolean',
            'recorded_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchGame::class, 'match_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'recorded_by_player_id');
    }
}
