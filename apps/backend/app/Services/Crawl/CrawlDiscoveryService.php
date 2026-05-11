<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Enums\CrawlJobStatus;
use App\Enums\CrawlTriggerType;
use App\Models\CrawlJob;

class CrawlDiscoveryService
{
    public function dispatch(
        string $country,
        int $limit = 200,
        float $minEcommerceScore = 0.3,
        bool $excludeExisting = true,
    ): CrawlJob {
        /** @var CrawlJob */
        return CrawlJob::query()->create([
            'domain_id' => null,
            'trigger_type' => CrawlTriggerType::Manual,
            'status' => CrawlJobStatus::Queued,
            'priority' => 1,
            'crawl_payload' => [
                'job_type' => 'domain_discovery_local_index',
                'countries' => [$country],
                'limit' => $limit,
                'min_sme_score' => $minEcommerceScore,
                'exclude_existing_domains' => $excludeExisting,
                'enqueue_homepage_fetch' => true,
                'enqueue_page_classification' => false,
            ],
        ]);
    }
}
