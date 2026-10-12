<?php

namespace App\Enums;

/**
 * Labels a host puts on roster entries. Unlike status, several can apply
 * at once, and each one changes what the app does with that player.
 */
enum ParticipantTag: string
{
    /** May manage the session: roster, rounds, scores. */
    case Host = 'host';

    /** Not here: skipped by round generation and hand-entered matches. */
    case NoShow = 'no_show';
}
