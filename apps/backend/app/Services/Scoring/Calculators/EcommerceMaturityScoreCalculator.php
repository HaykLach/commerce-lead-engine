<?php

declare(strict_types=1);

namespace App\Services\Scoring\Calculators;

use App\DTOs\Scoring\ScoreComponentResultData;

class EcommerceMaturityScoreCalculator implements ScoreCalculatorInterface
{
    use NormalizesScores;

    public function key(): string
    {
        return 'ecommerce_maturity_score';
    }

    public function calculate(array $signals): ScoreComponentResultData
    {
        $productCount = (int) ($signals['product_count'] ?? 0);
        $hasCart = (bool) ($signals['has_cart'] ?? false);
        $hasCheckout = (bool) ($signals['has_checkout'] ?? false);
        $hasSearch = (bool) ($signals['has_site_search'] ?? false);
        $paymentMethods = (int) ($signals['payment_methods_count'] ?? 0);

        $maturity = (40 * $this->ratio($productCount, 500))
            + ($hasCart ? 15 : 0)
            + ($hasCheckout ? 20 : 0)
            + ($hasSearch ? 10 : 0)
            + (15 * $this->ratio($paymentMethods, 4));

        $score = $this->clamp($maturity);

        $reasons = [sprintf('Ecommerce maturity is %d/100 based on catalog and conversion capabilities.', $score)];

        if (! $hasCheckout) {
            $reasons[] = 'Checkout flow was not detected, lowering ecommerce maturity.';
        }

        if ($productCount < 50) {
            $reasons[] = 'Small visible catalog suggests early-stage ecommerce operations.';
        }

        return new ScoreComponentResultData(
            score: $score,
            reasons: $reasons,
            rawMeasurements: [
                'product_count' => $productCount,
                'has_cart' => $hasCart,
                'has_checkout' => $hasCheckout,
                'has_site_search' => $hasSearch,
                'payment_methods_count' => $paymentMethods,
                'maturity_index' => $score,
            ],
        );
    }
}
