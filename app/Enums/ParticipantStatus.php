<?php

namespace App\Enums;

/**
 * Where a player stands on a session's roster. Only Confirmed players are
 * put into matches.
 */
enum ParticipantStatus: string
{
    /** Playing. Host-added and Reclub-imported players start here. */
    case Confirmed = 'confirmed';

    /** On the list but not playing (yet); a host can confirm them. */
    case Waitlisted = 'waitlisted';

    /** Asked to join; a host confirms or waitlists them. */
    case Requested = 'requested';

    /** Left the session. Kept so match history stays intact. */
    case Withdrawn = 'withdrawn';

    /**
     * Legacy: superseded by the no_show tag. Still in the database type
     * because Postgres cannot drop enum values; never written.
     */
    case NoShow = 'no_show';

    /**
     * Statuses a host can set directly.
     *
     * @return list<string>
     */
    public static function hostSettable(): array
    {
        return [self::Confirmed->value, self::Waitlisted->value];
    }
}
