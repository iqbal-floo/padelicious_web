<?php

namespace App\Models;

use App\Enums\PlayerGender;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Player extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'display_name', 'initials', 'avatar_url',
        'avatar_color_index', 'skill_level', 'skill_level_label', 'gender',
    ];

    /**
     * is_guest is a stored generated column; never write to it.
     */
    protected $guarded = ['id', 'is_guest'];

    protected function casts(): array
    {
        return [
            'gender' => PlayerGender::class,
            'avatar_color_index' => 'integer',
            'skill_level' => 'float',
            'is_guest' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function stats(): HasOne
    {
        return $this->hasOne(PlayerStat::class);
    }

    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(Club::class, 'club_members')
            ->withPivot(['role', 'status', 'joined_at', 'left_at']);
    }

    public function clubMemberships(): HasMany
    {
        return $this->hasMany(ClubMember::class);
    }

    public function hostedSessions(): HasMany
    {
        return $this->hasMany(Session::class, 'host_player_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(SessionParticipant::class);
    }
}
