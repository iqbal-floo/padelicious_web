<?php

namespace App\Enums;

enum ParticipantStatus: string
{
    case Active = 'active';
    case Queued = 'queued';
    case Withdrawn = 'withdrawn';

    /**
     * On the roster but not here yet. Skipped when generating rounds, and
     * reversible — unlike Withdrawn, which means they have left for good.
     */
    case NoShow = 'no_show';
}
