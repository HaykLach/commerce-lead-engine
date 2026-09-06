<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DailyOutreachReport;
use App\Services\Outreach\DailyReports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class SendDailyOutreachReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public int $reportId) {}

    public function handle(DailyReports $reports): void
    {
        if (! config('outreach_delivery.telegram_enabled') || blank(config('outreach_delivery.telegram_token')) || blank(config('outreach_delivery.telegram_chat_id'))) {
            return;
        }
        $report = DB::transaction(function (): ?DailyOutreachReport {
            $report = DailyOutreachReport::lockForUpdate()->find($this->reportId);
            if ($report === null || $report->notification_status !== 'pending') {
                return null;
            }
            $report->update(['notification_status' => 'sending', 'notification_started_at' => now()]);

            return $report;
        });
        if ($report === null) {
            return;
        }
        try {
            $response = Http::connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->post('https://api.telegram.org/bot'.config('outreach_delivery.telegram_token').'/sendMessage', [
                    'chat_id' => config('outreach_delivery.telegram_chat_id'), 'text' => $reports->text($report),
                    'link_preview_options' => ['is_disabled' => true],
                ]);
            if ($response->successful() && $response->json('ok') === true && filled($response->json('result.message_id'))) {
                $report->update(['notification_status' => 'sent', 'telegram_message_id' => (string) $response->json('result.message_id')]);
            } else {
                $report->update(['notification_status' => $response->json('ok') === false ? 'failed' : 'unknown',
                    'error' => 'Telegram did not confirm the notification. Check the chat and bot configuration.']);
            }
        } catch (Throwable) {
            // Never persist exception text: Telegram credentials occur in the request URL.
            $report->update(['notification_status' => 'unknown', 'error' => 'Telegram acceptance is uncertain. Check the chat; automatic resend is disabled.']);
        }
    }
}
