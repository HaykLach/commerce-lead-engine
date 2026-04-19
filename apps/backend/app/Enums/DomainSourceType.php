<?php

declare(strict_types=1);

namespace App\Enums;

enum DomainSourceType: string
{
    case Search = 'search';
    case SearchSeed = 'search_seed';
    case Directory = 'directory';
    case Expansion = 'expansion';
    case Sitemap = 'sitemap';
    case Referral = 'referral';
    case Import = 'import';
    case Manual = 'manual';
    case Crawler = 'crawler';
    case CommonCrawl = 'common_crawl';
}
