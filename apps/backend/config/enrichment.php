<?php

return [
    'scheduled' => (bool) env('WEBSITE_ENRICHMENT_SCHEDULED', false),
    'batch_size' => 25,
    'queue' => 'website-enrichment',
    'connection' => env('WEBSITE_ENRICHMENT_QUEUE_CONNECTION', 'database'),
    'fresh_days' => 7,
    'failure_retry_hours' => 24,
    // Hard ceilings in the runner keep each queue attempt below its timeout.
    'max_pages' => 6,
    'max_html_bytes' => 1_000_000,
    'page_timeout_seconds' => 8,
    'max_redirects' => 3,
    'max_page_attempts' => 3,
    'pace_milliseconds' => 1000,
    'user_agent' => 'FFPWebsiteAudit/1.0 (+https://ffptechnologies.com/)',
    'page_patterns' => [
        'contact' => '/contact|kontakt|կապ|контакт/iu',
        'impressum' => '/impressum|legal.notice/iu',
        'about' => '/about|ueber.uns|über.uns|մեր.մասին/iu',
        'category' => '/categor|collection|\/shop(?:\/|$)/iu',
        'product' => '/\/products?\/|\/item\//iu',
    ],
];
