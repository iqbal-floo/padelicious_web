<?php

namespace App\Models;

use App\Enums\PlayerGender;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'user_id', 'username', 'reclub_username', 'display_name', 'initials', 'avatar_url',
        'avatar_color_index', 'skill_level', 'skill_level_label', 'gender',
    ];

    /**
     * Lowercase letters, digits, dot and underscore; 2–30 characters.
     * Mirrors the players_username_format CHECK.
     */
    public const USERNAME_RULES = ['min:2', 'max:30', 'regex:/^[a-z0-9][a-z0-9._]{1,29}$/'];

    protected static function booted(): void
    {
        // Every player has a unique handle, even when created by a seeder,
        // a test or a social sign-in that never asked for one.
        static::creating(function (Player $player) {
            if (blank($player->username)) {
                $player->username = self::usernameFrom($player->display_name);
            }
        });
    }

    /**
     * Normalise on write so "Hardian.P" and "hardian.p" are the same handle.
     */
    protected function username(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)),
        );
    }

    /**
     * A free username derived from a display name: "Rina Hartono" ->
     * rina.hartono, then rina.hartono2 and so on.
     */
    public static function usernameFrom(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9._]+/', '.', mb_strtolower(trim($name))) ?? '', '.');

        if (strlen($base) < 2) {
            $base = 'player';
        }

        $base = substr($base, 0, 28);
        $candidate = $base;

        for ($n = 2; self::query()->where('username', $candidate)->exists(); $n++) {
            $candidate = $base.$n;
        }

        return $candidate;
    }

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
