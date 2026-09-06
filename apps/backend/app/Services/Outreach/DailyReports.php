<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use App\Jobs\SendDailyOutreachReport;
use App\Models\DailyOutreachReport;
use App\Models\OutreachDraft;
use App\Models\WebsiteAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class DailyReports
{
    public function bounds(string $date): array
    {
        validator(['date' => $date], ['date' => 'required|date_format:Y-m-d'])->validate();
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Yerevan');

        return [$start->utc(), $start->addDay()->utc()];
    }

    public function drafts(string $date): Builder
    {
        [$start, $end] = $this->bounds($date);

        return OutreachDraft::where('created_at', '>=', $start)->where('created_at', '<', $end);
    }

    public function audits(string $date): Builder
    {
        [$start, $end] = $this->bounds($date);

        return WebsiteAudit::where('created_at', '>=', $start)->where('created_at', '<', $end);
    }

    public function summary(string $date): array
    {
        return [
            'drafts' => $this->drafts($date)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'audits' => $this->audits($date)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'unfinished_audits' => WebsiteAudit::whereIn('status', ['queued', 'running'])->count(),
            'unfinished_drafts' => OutreachDraft::whereIn('status', ['queued', 'generating'])->count(),
            'drafting_enabled' => (bool) config('outreach.enabled') && filled(config('outreach.api_key')) && filled(config('outreach.model')),
        ];
    }

    public function prepare(string $date): DailyOutreachReport
    {
        $this->bounds($date);
        $report = DailyOutreachReport::firstOrCreate(['report_date' => $date], ['summary' => $this->summary($date), 'notification_status' => 'pending']);
        if (config('outreach_delivery.telegram_enabled') && filled(config('outreach_delivery.telegram_token'))
            && filled(config('outreach_delivery.telegram_chat_id')) && $report->notification_status === 'pending'
            && ($report->dispatched_at === null || $report->dispatched_at->lt(now()->subMinutes(5)))) {
            $connection = DeliveryQueue::connection();
            $report->update(['dispatched_at' => now()]);
            SendDailyOutreachReport::dispatch($report->id)->onConnection($connection)->onQueue('outreach-reports');
        }

        return $report;
    }

    public function text(DailyOutreachReport $report): string
    {
        $summary = $report->summary;
        $drafts = $summary['drafts'];
        $audits = $summary['audits'];
        $ready = $drafts['draft'] ?? 0;
        $unfinished = $summary['unfinished_audits'] + $summary['unfinished_drafts'];
        $failed = ($drafts['failed'] ?? 0) + ($drafts['blocked'] ?? 0) + ($audits['failed'] ?? 0);

        return "Outreach report for {$report->report_date} (Armenia)\n"
            ."Ready for review: $ready\nScheduled: ".($drafts['scheduled'] ?? 0)
            ."\nUnfinished jobs (including backlog): $unfinished\nFailed or blocked records: $failed"
            ."\nPartial audits: ".($audits['partial'] ?? 0)
            .($summary['drafting_enabled'] ? '' : "\nEmail drafting is not configured or enabled.")
            ."\nReview: ".rtrim(config('app.url'), '/').'/admin/outreach-reports?date='.$report->report_date;
    }
}
