<?php

namespace App\Models;

use App\Enums\RoundStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'session_id', 'round_number', 'status', 'generated_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
            'status' => RoundStatus::class,
            'generated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(MatchGame::class, 'round_id');
    }
}
