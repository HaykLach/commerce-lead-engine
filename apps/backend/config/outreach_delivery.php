<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('OUTREACH_SENDING_ENABLED', false),
    'connection' => env('OUTREACH_DELIVERY_QUEUE_CONNECTION', 'database'),
    'queue' => 'outreach-delivery',
    'daily_limit' => 100,
    'spacing_seconds' => 60,
    'timezone' => 'Asia/Yerevan',
    'telegram_enabled' => (bool) env('OUTREACH_TELEGRAM_ENABLED', false),
    'telegram_token' => env('TELEGRAM_BOT_TOKEN'),
    'telegram_chat_id' => env('TELEGRAM_REPORT_CHAT_ID'),
];
