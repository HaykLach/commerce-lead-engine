<?php

declare(strict_types=1);

namespace App\Enums;

enum DomainSourceType: string
{
    case Search = 'search';
    case Sitemap = 'sitemap';
    case Referral = 'referral';
    case Import = 'import';
    case Manual = 'manual';
    case Crawler = 'crawler';
}
