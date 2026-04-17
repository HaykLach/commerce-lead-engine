<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\Calculators\CustomStackComplexityScoreCalculator;
use App\Services\Scoring\Calculators\EcommerceMaturityScoreCalculator;
use App\Services\Scoring\Calculators\ErpAutomationFitScoreCalculator;
use App\Services\Scoring\Calculators\PlatformFitScoreCalculator;
use App\Services\Scoring\Calculators\PluginScriptBloatScoreCalculator;
use App\Services\Scoring\Calculators\ScoreCalculatorInterface;
use App\Services\Scoring\Calculators\SeoGapScoreCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScoreCalculatorsTest extends TestCase
{
    #[DataProvider('calculatorProvider')]
    public function test_calculator_returns_explainable_score_and_raw_measurements(
        ScoreCalculatorInterface $calculator,
        array $signals,
    ): void {
        $result = $calculator->calculate($signals);

        $this->assertGreaterThanOrEqual(0, $result->score);
        $this->assertLessThanOrEqual(100, $result->score);
        $this->assertNotEmpty($result->reasons);
        $this->assertIsArray($result->rawMeasurements);
        $this->assertNotEmpty($result->rawMeasurements);
    }

    public static function calculatorProvider(): array
    {
        return [
            'seo_gap' => [
                new SeoGapScoreCalculator(),
                [
                    'seo_score' => 35,
                    'meta_description_coverage' => 40,
                    'has_structured_data' => false,
                ],
            ],
            'plugin_script_bloat' => [
                new PluginScriptBloatScoreCalculator(),
                [
                    'script_count' => 52,
                    'third_party_script_count' => 16,
                    'total_js_kb' => 1800,
                ],
            ],
            'custom_stack_complexity' => [
                new CustomStackComplexityScoreCalculator(),
                [
                    'custom_technologies_count' => 6,
                    'unknown_technologies_count' => 3,
                    'integration_points_count' => 9,
                ],
            ],
            'ecommerce_maturity' => [
                new EcommerceMaturityScoreCalculator(),
                [
                    'product_count' => 350,
                    'has_cart' => true,
                    'has_checkout' => true,
                    'has_site_search' => true,
                    'payment_methods_count' => 3,
                ],
            ],
            'erp_automation_fit' => [
                new ErpAutomationFitScoreCalculator(),
                [
                    'monthly_order_volume' => 2400,
                    'sku_count' => 1800,
                    'uses_manual_fulfillment' => true,
                    'has_erp_integration' => false,
                ],
            ],
            'platform_fit' => [
                new PlatformFitScoreCalculator(),
                [
                    'platform' => 'shopify',
                    'platform_confidence' => 90,
                ],
            ],
        ];
    }
}
