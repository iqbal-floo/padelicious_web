<?php

namespace App\Enums;

enum PlayerGender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';
    case Undisclosed = 'undisclosed';
}
