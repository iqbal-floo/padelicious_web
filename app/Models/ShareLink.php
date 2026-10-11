<?php

namespace App\Models;

use App\Enums\ShareAccess;
use App\Enums\ShareResource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareLink extends Model
{
    use HasUuids;

    protected $table = 'share_links';

    public $timestamps = false;

    protected $fillable = [
        'token', 'resource_type', 'session_id', 'match_id', 'access',
        'created_by_player_id', 'expires_at', 'revoked_at', 'view_count', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'resource_type' => ShareResource::class,
            'access' => ShareAccess::class,
            'view_count' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchGame::class, 'match_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'created_by_player_id');
    }
}
