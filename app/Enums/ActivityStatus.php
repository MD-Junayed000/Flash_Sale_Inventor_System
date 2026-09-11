<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}
