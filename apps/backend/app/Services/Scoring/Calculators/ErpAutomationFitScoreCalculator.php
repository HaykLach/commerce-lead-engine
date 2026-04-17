<?php

declare(strict_types=1);

namespace App\Services\Scoring\Calculators;

use App\DTOs\Scoring\ScoreComponentResultData;

class ErpAutomationFitScoreCalculator implements ScoreCalculatorInterface
{
    use NormalizesScores;

    public function key(): string
    {
        return 'erp_automation_fit_score';
    }

    public function calculate(array $signals): ScoreComponentResultData
    {
        $monthlyOrders = (int) ($signals['monthly_order_volume'] ?? 0);
        $skuCount = (int) ($signals['sku_count'] ?? 0);
        $manualFulfillment = (bool) ($signals['uses_manual_fulfillment'] ?? true);
        $hasErp = (bool) ($signals['has_erp_integration'] ?? false);

        $fit = (35 * $this->ratio($monthlyOrders, 3000))
            + (30 * $this->ratio($skuCount, 5000))
            + ($manualFulfillment ? 20 : 0)
            + ($hasErp ? 0 : 15);

        $score = $this->clamp($fit);
        $reasons = [sprintf('ERP automation fit is %d/100 based on operational scale and process signals.', $score)];

        if ($manualFulfillment) {
            $reasons[] = 'Manual fulfillment signals an opportunity for workflow automation.';
        }

        if (! $hasErp) {
            $reasons[] = 'No ERP integration detected, increasing automation upside.';
        }

        return new ScoreComponentResultData(
            score: $score,
            reasons: $reasons,
            rawMeasurements: [
                'monthly_order_volume' => $monthlyOrders,
                'sku_count' => $skuCount,
                'uses_manual_fulfillment' => $manualFulfillment,
                'has_erp_integration' => $hasErp,
                'fit_index' => $score,
            ],
        );
    }
}
