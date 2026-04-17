<?php

declare(strict_types=1);

namespace Tests\Unit\Scoring;

use App\Services\Scoring\LeadScoringService;
use PHPUnit\Framework\TestCase;

class LeadScoringServiceTest extends TestCase
{
    public function test_it_computes_weighted_opportunity_score_and_aggregates_reasons(): void
    {
        $service = new LeadScoringService(configuredWeights: [
            'seo_gap_score' => 20,
            'plugin_script_bloat_score' => 15,
            'custom_stack_complexity_score' => 15,
            'ecommerce_maturity_score' => 20,
            'erp_automation_fit_score' => 20,
            'platform_fit_score' => 10,
        ]);

        $result = $service->score('example.com', [
            'seo_score' => 20,
            'meta_description_coverage' => 25,
            'has_structured_data' => false,
            'script_count' => 45,
            'third_party_script_count' => 12,
            'total_js_kb' => 1200,
            'custom_technologies_count' => 5,
            'unknown_technologies_count' => 2,
            'integration_points_count' => 7,
            'product_count' => 220,
            'has_cart' => true,
            'has_checkout' => true,
            'has_site_search' => true,
            'payment_methods_count' => 2,
            'monthly_order_volume' => 1500,
            'sku_count' => 1200,
            'uses_manual_fulfillment' => true,
            'has_erp_integration' => false,
            'platform' => 'shopify',
            'platform_confidence' => 88,
        ]);

        $this->assertSame('example.com', $result->domain);
        $this->assertGreaterThanOrEqual(0, $result->total);
        $this->assertLessThanOrEqual(100, $result->total);

        $this->assertArrayHasKey('component_scores', $result->breakdown);
        $this->assertArrayHasKey('raw_measurements', $result->breakdown);
        $this->assertArrayHasKey('reasons', $result->breakdown);

        $this->assertCount(6, $result->breakdown['component_scores']);
        $this->assertNotEmpty($result->breakdown['reasons']);
        $this->assertStringContainsString('[seo_gap_score]', $result->breakdown['reasons'][0]);
    }
}
