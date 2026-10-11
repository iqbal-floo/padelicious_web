<?php

namespace App\Models;

use App\Enums\ClubRole;
use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot-style model for the club_members composite-key table.
 */
class ClubMember extends Model
{
    protected $table = 'club_members';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['club_id', 'player_id', 'role', 'status', 'joined_at', 'left_at'];

    protected function casts(): array
    {
        return [
            'role' => ClubRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
