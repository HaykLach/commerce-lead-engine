<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\LeadScore;

class LeadScoreIngestionService
{
    public function store(array $payload): LeadScore
    {
        $leadScore = LeadScore::query()->create($payload);

        Domain::query()->whereKey($leadScore->domain_id)->update([
            'status' => DomainStatus::Processed,
        ]);

        return $leadScore->fresh();
    }
}
