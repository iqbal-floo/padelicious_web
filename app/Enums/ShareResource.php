<?php

namespace App\Enums;

enum ShareResource: string
{
    case Session = 'session';
    case Leaderboard = 'leaderboard';
    case MatchAccess = 'match';
}
