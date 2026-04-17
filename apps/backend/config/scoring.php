<?php

declare(strict_types=1);

return [
    'enabled' => env('SCORING_ENABLED', true),

    'weights' => [
        'platform_confidence' => (int) env('SCORING_WEIGHT_PLATFORM_CONFIDENCE', 25),
        'product_page_presence' => (int) env('SCORING_WEIGHT_PRODUCT_PAGE_PRESENCE', 20),
        'checkout_detection' => (int) env('SCORING_WEIGHT_CHECKOUT_DETECTION', 20),
        'contact_page_presence' => (int) env('SCORING_WEIGHT_CONTACT_PAGE_PRESENCE', 10),
        'technology_signal' => (int) env('SCORING_WEIGHT_TECHNOLOGY_SIGNAL', 15),
        'crawl_freshness' => (int) env('SCORING_WEIGHT_CRAWL_FRESHNESS', 10),
    ],

    'thresholds' => [
        'hot' => (int) env('SCORING_THRESHOLD_HOT', 80),
        'warm' => (int) env('SCORING_THRESHOLD_WARM', 50),
        'cold' => (int) env('SCORING_THRESHOLD_COLD', 0),
    ],
];
