<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Court extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['session_id', 'court_number', 'label'];

    protected function casts(): array
    {
        return ['court_number' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(Session::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(MatchGame::class, 'court_id');
    }
}
