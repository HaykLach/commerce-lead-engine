<?php

declare(strict_types=1);

namespace App\Enums;

enum DomainStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Crawling = 'crawling';
    case Processed = 'processed';
    case Failed = 'failed';
}
