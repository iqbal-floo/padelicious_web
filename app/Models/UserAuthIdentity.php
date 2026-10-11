<?php

namespace App\Models;

use App\Enums\AuthProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAuthIdentity extends Model
{
    use HasUuids;

    protected $table = 'user_auth_identities';

    public $timestamps = false;

    protected $fillable = [
        'user_id', 'provider', 'provider_user_id', 'email', 'raw_profile', 'created_at',
    ];

    protected $hidden = ['raw_profile'];

    protected function casts(): array
    {
        return [
            'provider' => AuthProvider::class,
            'raw_profile' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
