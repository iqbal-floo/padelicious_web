<?php

namespace App\Enums;

enum ParticipantSource: string
{
    case Manual = 'manual';
    case Club = 'club';
    case Reclub = 'reclub';
}
