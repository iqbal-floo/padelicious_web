<?php

namespace App\Policies;

use App\Enums\ParticipantRole;
use App\Models\Session;
use App\Models\User;

class SessionPolicy
{
    /**
     * Only the host, or a participant marked admin, may change a session.
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
            ->where('role', ParticipantRole::Admin->value)
            ->exists();
    }
}
