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
                    'seo_gap_score' => 20,
                    'plugin_script_bloat_score' => 15,
                    'custom_stack_complexity_score' => 15,
                    'ecommerce_maturity_score' => 20,
                    'erp_automation_fit_score' => 20,
                    'platform_fit_score' => 10,
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
