<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('PAGESPEED_ENABLED', false),
    'scheduled' => (bool) env('PAGESPEED_SCHEDULED', false),
    'api_key' => env('PAGESPEED_API_KEY'),
    'desktop' => (bool) env('PAGESPEED_DESKTOP', false),
    'connection' => env('PAGESPEED_QUEUE_CONNECTION', 'database'),
    'queue' => 'pagespeed',
    'cache_store' => env('PAGESPEED_CACHE_STORE', 'database'),
    'batch_size' => 25,
    'fresh_days' => 7,
    'failure_retry_hours' => 24,
    'requests_per_minute' => 6,
    'requests_per_day' => 300,
    'timeout_seconds' => 60,
    'max_response_bytes' => 8_000_000,
    'poor_score_below' => 50,
    'good_score_from' => 90,
];
