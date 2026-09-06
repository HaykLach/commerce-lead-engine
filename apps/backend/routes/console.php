<?php

declare(strict_types=1);

use App\Models\DailyOutreachReport;
use App\Services\Outreach\DailyReports;
use App\Services\Outreach\MessageDelivery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:about-leads', function (): void {
    $this->comment('Commerce lead engine backend skeleton is ready.');
})->purpose('Display backend skeleton status');

Schedule::command('websites:enrich --limit='.min(100, max(1, (int) config('enrichment.batch_size'))))
    ->everyFiveMinutes()->withoutOverlapping()->when(fn (): bool => (bool) config('enrichment.scheduled'));

Schedule::command('websites:pagespeed --limit='.min(100, max(1, (int) config('pagespeed.batch_size'))))
    ->everyFiveMinutes()->withoutOverlapping()->when(fn (): bool => config('pagespeed.enabled') && config('pagespeed.scheduled'));

Schedule::command('outreach:draft --limit='.min(100, max(1, (int) config('outreach.batch_size'))))
    ->everyFiveMinutes()->withoutOverlapping()->when(fn (): bool => config('outreach.enabled') && config('outreach.scheduled'));

Artisan::command('outreach:send-due', function (): void {
    $count = app(MessageDelivery::class)->dispatchDue();
    $this->info("Queued $count due messages.");
})->purpose('Queue approved outreach whose scheduled time has arrived');

Artisan::command('outreach:report {--date=}', function (): void {
    $date = $this->option('date') ?: now('Asia/Yerevan')->toDateString();
    $report = app(DailyReports::class)->prepare($date);
    $this->info("Report $report->report_date: $report->notification_status");
})->purpose('Create the daily review summary and queue its Telegram notification');

Schedule::command('outreach:send-due')->everyMinute()->withoutOverlapping();
// Catch up after a short scheduler/queue outage; the unique date and atomic claim prevent duplicate summaries.
Schedule::call(function (): void {
    DailyOutreachReport::where('notification_status', 'sending')
        ->where('notification_started_at', '<', now()->subMinutes(5))
        ->update(['notification_status' => 'unknown', 'error' => 'Worker stopped during notification. Check Telegram before retrying.']);
    app(DailyReports::class)->prepare(now('Asia/Yerevan')->toDateString());
})->name('daily-outreach-report')->everyMinute()->timezone('Asia/Yerevan')->when(fn (): bool => now('Asia/Yerevan')->hour >= 22)->withoutOverlapping();
