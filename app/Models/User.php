<?php

namespace App\Models;

use App\Enums\UserStatus;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['full_name', 'email', 'password_hash', 'phone', 'status'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * The schema (docs/schema.sql) uses a uuid primary key, not an
     * auto-incrementing bigint.
     */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * schema.sql has no remember_token column; refresh tokens live in
     * the user_sessions table instead.
     */
    protected $rememberTokenName = null;

    /**
     * The schema stores the hash in password_hash rather than password.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'accepted_terms_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password_hash' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    public function player(): HasOne
    {
        return $this->hasOne(Player::class);
    }

    public function authIdentities(): HasMany
    {
        return $this->hasMany(UserAuthIdentity::class);
    }
}
