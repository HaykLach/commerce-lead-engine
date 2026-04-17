<?php

declare(strict_types=1);

namespace App\Enums;

enum CrawlTriggerType: string
{
    case Discovery = 'discovery';
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Recrawl = 'recrawl';
}
