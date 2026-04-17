<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Models\Domain;
use App\Models\DomainMetric;
use Illuminate\Support\Carbon;

class DomainMetricIngestionService
{
    public function store(array $payload): DomainMetric
    {
        $metric = DomainMetric::query()->create($payload);

        Domain::query()->whereKey($metric->domain_id)->update([
            'platform' => $metric->platform,
            'confidence' => $metric->confidence,
            'country' => $metric->country,
            'niche' => $metric->niche,
            'business_model' => $metric->business_model,
            'last_crawled_at' => $metric->measured_at ?? Carbon::now(),
            'last_seen_at' => Carbon::now(),
        ]);

        return $metric->fresh();
    }
}
