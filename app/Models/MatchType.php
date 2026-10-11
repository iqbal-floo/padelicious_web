<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchType extends Model
{
    protected $table = 'match_types';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'code', 'label', 'category', 'is_team_based',
        'is_mixed', 'requires_club', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_team_based' => 'boolean',
            'is_mixed' => 'boolean',
            'requires_club' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'match_type_code', 'code');
    }
}
