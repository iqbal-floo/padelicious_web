<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerStat extends Model
{
    protected $table = 'player_stats';

    protected $primaryKey = 'player_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    /**
     * win_rate is a stored generated column; never write to it.
     */
    protected $guarded = ['player_id', 'win_rate'];

    protected function casts(): array
    {
        return [
            'sessions_played' => 'integer',
            'matches_played' => 'integer',
            'matches_won' => 'integer',
            'matches_lost' => 'integer',
            'total_points' => 'integer',
            'win_rate' => 'float',
            'last_played_at' => 'datetime',
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
