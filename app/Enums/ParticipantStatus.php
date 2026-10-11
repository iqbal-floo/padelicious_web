<?php

namespace App\Enums;

enum ParticipantStatus: string
{
    case Active = 'active';
    case Queued = 'queued';
    case Withdrawn = 'withdrawn';
}
