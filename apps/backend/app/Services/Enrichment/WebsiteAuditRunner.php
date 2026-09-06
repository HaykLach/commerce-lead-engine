<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\WebsiteAudit;
use App\Models\WebsiteAuditPage;
use App\Services\Contacts\ContactIngestionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class WebsiteAuditRunner
{
    public function __construct(private readonly PublicPageFetcher $fetcher, private readonly HtmlPageAnalyzer $analyzer, private readonly ContactIngestionService $contacts) {}

    public function run(int $auditId): void
    {
        $token = (string) Str::uuid();
        $audit = DB::transaction(function () use ($auditId, $token): ?WebsiteAudit {
            $audit = WebsiteAudit::query()->lockForUpdate()->find($auditId);
            if ($audit === null || $audit->active_domain_id === null || $audit->lease_until?->isFuture()) {
                return null;
            }
            $audit->update(['status' => 'running', 'attempts' => $audit->attempts + 1, 'started_at' => $audit->started_at ?? now(), 'lease_token' => $token, 'lease_until' => now()->addSeconds(85), 'error' => null]);

            return $audit;
        });
        if ($audit === null) {
            return;
        }
        try {
            $this->process($audit, $token);
        } catch (Throwable $exception) {
            WebsiteAudit::query()->whereKey($auditId)->where('lease_token', $token)->whereNotNull('active_domain_id')
                ->update(['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'updated_at' => now()]);
            throw $exception;
        }
    }

    private function process(WebsiteAudit $audit, string $token): void
    {
        $auditId = $audit->id;
        if ($audit->attempts > 5) {
            $this->fail($auditId, 'The audit exceeded its recovery limit.');

            return;
        }
        $domain = $audit->domain;
        foreach ($audit->pages()->where('status', 'completed')->get() as $completedPage) {
            $this->planPages($auditId, $token, $completedPage->evidence['links']);
        }
        $visited = [];
        $requests = 0;
        while ($page = $audit->pages()->where('status', '!=', 'completed')->where('retryable', true)->whereNotIn('id', $visited)->first()) {
            $visited[] = $page->id;
            if ($page->attempts >= min(3, max(1, (int) config('enrichment.max_page_attempts')))) {
                $this->savePage($auditId, $token, $page, ['status' => 'failed', 'retryable' => false, 'error' => 'The page exceeded its attempt limit.']);

                continue;
            }
            if ($requests++ > 0) {
                usleep(min(1000, max(0, (int) config('enrichment.pace_milliseconds'))) * 1000);
            }
            $this->savePage($auditId, $token, $page, ['status' => 'fetching', 'attempts' => $page->attempts + 1]);
            try {
                $response = $this->fetcher->fetch($page->url, $domain->normalized_domain);
                $evidence = $this->analyzer->analyze($response['html'], $response['url']);
                $this->savePage($auditId, $token, $page, ['status' => 'completed', 'final_url' => $response['url'], 'http_status' => $response['status'], 'fetched_at' => now(), 'evidence' => $evidence, 'error' => null, 'retryable' => false]);
                $this->planPages($auditId, $token, $evidence['links']);
            } catch (FetchException $exception) {
                $this->savePage($auditId, $token, $page, ['status' => 'failed', 'retryable' => $exception->retryable && $page->attempts < min(3, max(1, (int) config('enrichment.max_page_attempts'))), 'http_status' => $exception->httpStatus, 'error' => $exception->getMessage()]);
            }
        }
        $retry = $audit->pages()->where('status', '!=', 'completed')->where('retryable', true)->exists();
        if ($retry) {
            WebsiteAudit::query()->whereKey($auditId)->where('lease_token', $token)->update(['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'error' => 'Some pages will be retried.', 'updated_at' => now()]);
            throw new RuntimeException('Retrying transient website audit failures.');
        }
        $this->finish($auditId, $token);
    }

    private function savePage(int $auditId, string $token, WebsiteAuditPage $page, array $attributes): void
    {
        DB::transaction(function () use ($auditId, $token, $page, $attributes): void {
            $audit = WebsiteAudit::query()->lockForUpdate()->findOrFail($auditId);
            if ($audit->lease_token !== $token || $audit->active_domain_id === null) {
                throw new RuntimeException('The audit lease is no longer owned by this worker.');
            }
            $page->update($attributes);
        });
    }

    private function planPages(int $auditId, string $token, array $links): void
    {
        DB::transaction(function () use ($auditId, $token, $links): void {
            $audit = WebsiteAudit::query()->lockForUpdate()->findOrFail($auditId);
            if ($audit->lease_token !== $token) {
                throw new RuntimeException('The audit lease changed.');
            }
            $domain = $audit->domain;
            $classification = $domain->latestPageClassification;
            foreach (['category' => $classification?->sample_category_url, 'product' => $classification?->sample_product_url, 'contact' => data_get($domain->metadata, 'contact_url')] as $kind => $url) {
                if (is_string($url)) {
                    array_unshift($links, ['url' => $url, 'kind' => $kind]);
                }
            }
            $maxPages = min(6, max(1, (int) config('enrichment.max_pages')));
            foreach (['contact', 'category', 'product', 'impressum', 'about'] as $kind) {
                if ($audit->pages()->count() >= $maxPages) {
                    break;
                }
                if ($audit->pages()->where('kind', $kind)->exists()) {
                    continue;
                }
                foreach ($links as $link) {
                    $url = AuditUrl::normalize($link['url']);
                    if ($link['kind'] !== $kind || $url === null || ! AuditUrl::sameSite($url, $domain->normalized_domain)
                        || preg_match('/cart|checkout|logout|add.to|delete|remove/i', rawurldecode($url))) {
                        continue;
                    }
                    if (! $audit->pages()->where('url_hash', hash('sha256', $url))->exists()) {
                        $audit->pages()->create(['url' => $url, 'url_hash' => hash('sha256', $url), 'kind' => $kind]);
                        break;
                    }
                }
            }
        });
    }

    public function fail(int $auditId, string $message): void
    {
        $this->finish($auditId, null, $message);
    }

    private function finish(int $auditId, ?string $token, ?string $failure = null): void
    {
        DB::transaction(function () use ($auditId, $token, $failure): void {
            $audit = WebsiteAudit::query()->lockForUpdate()->find($auditId);
            if ($audit === null || $audit->active_domain_id === null || ($token !== null && $audit->lease_token !== $token)) {
                return;
            }
            $pages = $audit->pages()->get();
            $completed = $pages->where('status', 'completed');
            $status = $completed->isEmpty() ? 'failed' : ($failure === null && $completed->count() === $pages->count() ? 'completed' : 'partial');
            $summary = [
                'pages_planned' => $pages->count(), 'pages_completed' => $completed->count(),
                'coverage' => $completed->pluck('kind')->values()->all(),
                'contact_scan' => [
                    'sampled_urls' => $pages->pluck('url')->all(),
                    'pages_scanned' => $pages->map(fn (WebsiteAuditPage $page): array => [
                        'url' => $page->url, 'final_url' => $page->final_url ?? $page->url,
                        'observed_at' => $page->fetched_at?->toIso8601String(),
                        'contacts' => $page->status === 'completed' ? $page->evidence['contacts'] : ['version' => 1, 'status' => 'skipped', 'reason' => 'fetch_failed'],
                    ])->all(),
                ],
            ];
            $audit->update(['status' => $status, 'active_domain_id' => null, 'lease_token' => null, 'lease_until' => null, 'finished_at' => now(),
                'expires_at' => $status === 'completed' ? now()->addDays(max(1, (int) config('enrichment.fresh_days'))) : now()->addHours(max(1, (int) config('enrichment.failure_retry_hours'))),
                'summary' => $summary, 'error' => $failure ?? ($status === 'completed' ? null : 'One or more selected pages could not be analyzed.')]);
            $this->contacts->ingestAudit($audit);
        }, 3);
    }
}
