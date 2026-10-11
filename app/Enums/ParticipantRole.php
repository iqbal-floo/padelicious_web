<?php

namespace App\Enums;

enum ParticipantRole: string
{
    case Host = 'host';
    case Admin = 'admin';
    case Player = 'player';
}
