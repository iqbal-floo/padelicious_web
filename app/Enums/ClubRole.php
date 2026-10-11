<?php

namespace App\Enums;

enum ClubRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
