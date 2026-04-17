<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\LeadScoreResultData;

class LeadScoringService
{
    public function score(string $domain, array $signals = []): LeadScoreResultData
    {
        $weights = config('scoring.weights', []);

        // Placeholder: scoring calculation will use configurable weights.
        return new LeadScoreResultData(
            domain: $domain,
            total: 0,
            breakdown: [
                'weights' => $weights,
                'signals' => $signals,
            ],
        );
    }
}
