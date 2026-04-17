<?php

declare(strict_types=1);

namespace App\Services\Domain;

use App\DTOs\DomainDiscoveryRequestData;
use App\Jobs\ProcessDomainCrawlJob;
use App\Models\Domain;

class DomainDiscoveryService
{
    public function queueDomain(DomainDiscoveryRequestData $data): Domain
    {
        // Placeholder skeleton: persistence and dedupe rules will be added later.
        $domain = new Domain([
            'domain' => $data->domain,
            'metadata' => ['source' => $data->source],
        ]);

        ProcessDomainCrawlJob::dispatch($data->domain);

        return $domain;
    }
}
