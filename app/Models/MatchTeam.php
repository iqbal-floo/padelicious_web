<?php

namespace App\Models;

use App\Enums\TeamSide;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchTeam extends Model
{
    use HasUuids;

    protected $table = 'match_teams';

    public $timestamps = false;

    protected $fillable = ['match_id', 'side', 'score_points'];

    protected function casts(): array
    {
        return [
            'side' => TeamSide::class,
            'score_points' => 'integer',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(MatchGame::class, 'match_id');
    }

    public function players(): BelongsToMany
    {
        return $this->belongsToMany(
            SessionParticipant::class,
            'match_team_players',
            'match_team_id',
            'participant_id'
        )->withPivot('slot');
    }

    public function teamPlayers(): HasMany
    {
        return $this->hasMany(MatchTeamPlayer::class, 'match_team_id');
    }
}
