<?php

declare(strict_types=1);

return [
    'enabled' => env('SCORING_ENABLED', true),

    'weights' => [
        'seo_gap_score' => (int) env('SCORING_WEIGHT_SEO_GAP_SCORE', 20),
        'plugin_script_bloat_score' => (int) env('SCORING_WEIGHT_PLUGIN_SCRIPT_BLOAT_SCORE', 15),
        'custom_stack_complexity_score' => (int) env('SCORING_WEIGHT_CUSTOM_STACK_COMPLEXITY_SCORE', 15),
        'ecommerce_maturity_score' => (int) env('SCORING_WEIGHT_ECOMMERCE_MATURITY_SCORE', 20),
        'erp_automation_fit_score' => (int) env('SCORING_WEIGHT_ERP_AUTOMATION_FIT_SCORE', 20),
        'platform_fit_score' => (int) env('SCORING_WEIGHT_PLATFORM_FIT_SCORE', 10),
    ],

    'thresholds' => [
        'hot' => (int) env('SCORING_THRESHOLD_HOT', 80),
        'warm' => (int) env('SCORING_THRESHOLD_WARM', 50),
        'cold' => (int) env('SCORING_THRESHOLD_COLD', 0),
    ],
];
