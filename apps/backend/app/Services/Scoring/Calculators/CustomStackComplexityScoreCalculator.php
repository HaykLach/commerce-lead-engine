<?php

declare(strict_types=1);

namespace App\Services\Scoring\Calculators;

use App\DTOs\Scoring\ScoreComponentResultData;

class CustomStackComplexityScoreCalculator implements ScoreCalculatorInterface
{
    use NormalizesScores;

    public function key(): string
    {
        return 'custom_stack_complexity_score';
    }

    public function calculate(array $signals): ScoreComponentResultData
    {
        $customTechCount = (int) ($signals['custom_technologies_count'] ?? 0);
        $unknownTechCount = (int) ($signals['unknown_technologies_count'] ?? 0);
        $integrationPoints = (int) ($signals['integration_points_count'] ?? 0);

        $complexityIndex = (45 * $this->ratio($customTechCount, 8))
            + (25 * $this->ratio($unknownTechCount, 5))
            + (30 * $this->ratio($integrationPoints, 10));

        $score = $this->clamp($complexityIndex);
        $reasons = [sprintf('Custom stack complexity is %d/100 based on custom tech and integration footprint.', $score)];

        if ($customTechCount >= 4) {
            $reasons[] = 'Multiple custom technologies detected, increasing implementation complexity.';
        }

        if ($integrationPoints >= 6) {
            $reasons[] = 'Numerous integrations indicate higher coordination and maintenance overhead.';
        }

        return new ScoreComponentResultData(
            score: $score,
            reasons: $reasons,
            rawMeasurements: [
                'custom_technologies_count' => $customTechCount,
                'unknown_technologies_count' => $unknownTechCount,
                'integration_points_count' => $integrationPoints,
                'complexity_index' => $score,
            ],
        );
    }
}
