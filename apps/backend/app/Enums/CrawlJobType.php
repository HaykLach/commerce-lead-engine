<?php

declare(strict_types=1);

namespace App\Enums;

enum CrawlJobType: string
{
    case HomepageFetch = 'homepage_fetch';
    case PageClassification = 'page_classification';
    case DomainDiscoverySearchSeed = 'domain_discovery_search_seed';
    case DomainDiscoveryDirectory = 'domain_discovery_directory';
    case DomainDiscoveryExpansion = 'domain_discovery_expansion';
    case DomainDiscoveryCommonCrawl = 'domain_discovery_common_crawl';
    case CommonCrawlImport = 'common_crawl_import';
    case DomainDiscoveryLocalIndex = 'domain_discovery_local_index';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
