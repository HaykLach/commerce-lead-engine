<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:about-leads', function (): void {
    $this->comment('Commerce lead engine backend skeleton is ready.');
})->purpose('Display backend skeleton status');

Schedule::command('websites:enrich --limit='.min(100, max(1, (int) config('enrichment.batch_size'))))
    ->everyFiveMinutes()->withoutOverlapping()->when(fn (): bool => (bool) config('enrichment.scheduled'));
