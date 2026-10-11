<?php

namespace App\Models;

use App\Enums\ClubVisibility;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Club extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'slug', 'name', 'emoji', 'theme_color', 'description',
        'city', 'visibility', 'owner_player_id',
    ];

    /**
     * member_count is maintained by the trg_club_member_count trigger.
     */
    protected $guarded = ['id', 'member_count'];

    protected function casts(): array
    {
        return [
            'visibility' => ClubVisibility::class,
            'member_count' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'owner_player_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Player::class, 'club_members')
            ->withPivot(['role', 'status', 'joined_at', 'left_at']);
    }

    public function clubMembers(): HasMany
    {
        return $this->hasMany(ClubMember::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class);
    }
}
