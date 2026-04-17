<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ScoreConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScoreConfig>
 */
class ScoreConfigFactory extends Factory
{
    protected $model = ScoreConfig::class;

    public function definition(): array
    {
        return [
            'name' => 'default',
            'version' => 'v1',
            'is_active' => true,
            'weights' => [
                'platform_confidence' => 25,
                'product_page_presence' => 20,
                'checkout_detection' => 20,
                'contact_page_presence' => 10,
                'technology_signal' => 15,
                'crawl_freshness' => 10,
            ],
            'thresholds' => [
                'hot' => 80,
                'warm' => 50,
                'cold' => 0,
            ],
            'rules' => [
                'max_score' => 100,
            ],
            'metadata' => [
                'seeded_by' => 'factory',
            ],
            'effective_from' => now(),
            'effective_to' => null,
        ];
    }
}
