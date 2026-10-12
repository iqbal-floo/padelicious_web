<?php

namespace App\Policies;

use App\Enums\ParticipantRole;
use App\Enums\ParticipantStatus;
use App\Enums\ParticipantTag;
use App\Models\Session;
use App\Models\User;

class SessionPolicy
{
    /**
     * The creator, or any participant tagged host, may change a session.
     * (The legacy admin role still counts.)
     */
    public function manage(User $user, Session $session): bool
    {
        $playerId = $user->player?->id;

        if (! $playerId) {
            return false;
        }

        if ($session->host_player_id === $playerId) {
            return true;
        }

        return $session->participants()
            ->where('player_id', $playerId)
            ->where('status', '!=', ParticipantStatus::Withdrawn->value)
            ->where(fn ($q) => $q
                ->whereRaw('tags @> ?::jsonb', [json_encode([ParticipantTag::Host->value])])
                ->orWhere('role', ParticipantRole::Admin->value))
            ->exists();
    }
}
