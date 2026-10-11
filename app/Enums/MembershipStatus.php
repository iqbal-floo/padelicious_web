<?php

namespace App\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case Left = 'left';
    case Banned = 'banned';
}
