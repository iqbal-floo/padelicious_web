<?php

namespace App\Enums;

enum AuthProvider: string
{
    case Google = 'google';
    case Apple = 'apple';
    case Facebook = 'facebook';
    case Reclub = 'reclub';
}
