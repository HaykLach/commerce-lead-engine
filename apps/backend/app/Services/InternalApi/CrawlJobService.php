<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Enums\DomainStatus;
use App\Models\CrawlJob;
use App\Models\Domain;

class CrawlJobService
{
    public function create(array $payload): CrawlJob
    {
        $crawlJob = CrawlJob::query()->create($payload);

        Domain::query()->whereKey($crawlJob->domain_id)->update([
            'status' => DomainStatus::Queued,
        ]);

        return $crawlJob->fresh();
    }
}
