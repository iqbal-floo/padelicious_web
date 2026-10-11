<?php

namespace App\Enums;

enum ClubVisibility: string
{
    case PublicAccess = 'public';
    case PrivateAccess = 'private';
    case InviteOnly = 'invite_only';
}
