<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionQueue extends Model
{
    use HasUuids;

    protected $table = 'session_queue';

    public $timestamps = false;

    protected $fillable = [
        'session_id', 'participant_id', 'position', 'enqueued_at', 'dequeued_at',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'enqueued_at' => 'datetime',
            'dequeued_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(SessionParticipant::class, 'participant_id');
    }
}
