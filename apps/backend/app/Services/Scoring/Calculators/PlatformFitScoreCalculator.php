<?php

declare(strict_types=1);

namespace App\Services\Scoring\Calculators;

use App\DTOs\Scoring\ScoreComponentResultData;

class PlatformFitScoreCalculator implements ScoreCalculatorInterface
{
    use NormalizesScores;

    private const PLATFORM_BASELINE = [
        'shopify' => 90,
        'woocommerce' => 85,
        'magento' => 80,
        'prestashop' => 75,
        'bigcommerce' => 80,
        'shopware' => 70,
        'custom' => 60,
        'unknown' => 40,
    ];

    public function key(): string
    {
        return 'platform_fit_score';
    }

    public function calculate(array $signals): ScoreComponentResultData
    {
        $platform = strtolower((string) ($signals['platform'] ?? 'unknown'));
        $confidence = $this->clamp((float) ($signals['platform_confidence'] ?? 50));

        $baseline = self::PLATFORM_BASELINE[$platform] ?? self::PLATFORM_BASELINE['unknown'];
        $score = $this->clamp(($baseline * 0.7) + ($confidence * 0.3));

        $reasons = [sprintf('Platform fit is %d/100 for %s with %d%% detection confidence.', $score, $platform, $confidence)];

        if ($platform === 'unknown') {
            $reasons[] = 'Platform is unknown; confidence penalty applied until detection improves.';
        }

        return new ScoreComponentResultData(
            score: $score,
            reasons: $reasons,
            rawMeasurements: [
                'platform' => $platform,
                'platform_confidence' => $confidence,
                'platform_baseline' => $baseline,
            ],
        );
    }
}
