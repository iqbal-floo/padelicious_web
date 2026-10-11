<?php

namespace App\Enums;

enum ImportStatus: string
{
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
}
