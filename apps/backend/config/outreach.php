<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('OUTREACH_DRAFTING_ENABLED', false),
    'scheduled' => (bool) env('OUTREACH_DRAFTING_SCHEDULED', false),
    'api_key' => env('OPENAI_API_KEY'),
    // Choose a Responses API model supporting strict structured outputs before enabling.
    'model' => env('OUTREACH_DRAFT_MODEL'),
    'connection' => env('OUTREACH_DRAFT_QUEUE_CONNECTION', 'database'),
    'queue' => 'outreach-drafts',
    'cache_store' => env('OUTREACH_DRAFT_CACHE_STORE', 'database'),
    'requests_per_day' => 100,
    'batch_size' => 25,
    'max_output_tokens' => 1500,
    'prompt_version' => 'ffp-draft-v1',
    'company' => 'FFP',
    'positioning' => 'We help eCommerce businesses improve their storefronts, search visibility, and integrations so their online shop supports growth.',
    'services' => ['eCommerce development', 'website performance optimization', 'technical SEO', 'ERP and CRM integrations', 'workflow automation'],
    'cta' => 'A great first step to improving your business would be a quick 10-minute call with me. When would be a convenient time in the next week for you to connect?',
    'signature' => "Best regards,\nRuben Simonyan",
];
