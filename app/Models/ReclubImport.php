<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReclubImport extends Model
{
    use HasUuids;

    protected $table = 'reclub_imports';

    public $timestamps = false;

    protected $fillable = [
        'session_id', 'requested_by_player_id', 'match_code', 'status',
        'imported_count', 'error_message', 'raw_response',
        'created_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'imported_count' => 'integer',
            'raw_response' => 'array',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'requested_by_player_id');
    }
}
