<?php

declare(strict_types=1);

namespace App\Services\Scoring\Calculators;

use App\DTOs\Scoring\ScoreComponentResultData;

class SeoGapScoreCalculator implements ScoreCalculatorInterface
{
    use NormalizesScores;

    public function key(): string
    {
        return 'seo_gap_score';
    }

    public function calculate(array $signals): ScoreComponentResultData
    {
        $seoScore = $this->clamp((float) ($signals['seo_score'] ?? 50));
        $metaCoverage = $this->clamp((float) ($signals['meta_description_coverage'] ?? 50));
        $hasStructuredData = (bool) ($signals['has_structured_data'] ?? false);

        $currentSeoHealth = (0.6 * $seoScore) + (0.3 * $metaCoverage) + ($hasStructuredData ? 10 : 0);
        $gapScore = $this->clamp(100 - $currentSeoHealth);

        $reasons = [
            sprintf('SEO health baseline is %d/100; gap opportunity computed at %d.', $this->clamp($currentSeoHealth), $gapScore),
        ];

        if (! $hasStructuredData) {
            $reasons[] = 'Structured data was not detected, increasing SEO opportunity.';
        }

        if ($metaCoverage < 60) {
            $reasons[] = 'Meta description coverage is below 60%, indicating optimization headroom.';
        }

        return new ScoreComponentResultData(
            score: $gapScore,
            reasons: $reasons,
            rawMeasurements: [
                'seo_score' => $seoScore,
                'meta_description_coverage' => $metaCoverage,
                'has_structured_data' => $hasStructuredData,
                'current_seo_health' => $this->clamp($currentSeoHealth),
            ],
        );
    }
}
