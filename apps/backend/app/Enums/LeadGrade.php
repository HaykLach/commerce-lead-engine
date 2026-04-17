<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadGrade: string
{
    case Hot = 'hot';
    case Warm = 'warm';
    case Cold = 'cold';
}
