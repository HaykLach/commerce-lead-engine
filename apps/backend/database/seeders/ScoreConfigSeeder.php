<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ScoreConfig;
use Illuminate\Database\Seeder;

class ScoreConfigSeeder extends Seeder
{
    public function run(): void
    {
        ScoreConfig::query()->update(['is_active' => false]);

        ScoreConfig::query()->updateOrCreate(
            ['name' => 'default', 'version' => 'v1'],
            [
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
                    'requires_recent_crawl_hours' => 72,
                ],
                'metadata' => [
                    'description' => 'Default lead scoring profile',
                    'explainable_reasons' => true,
                ],
                'effective_from' => now(),
                'effective_to' => null,
            ],
        );
    }
}
