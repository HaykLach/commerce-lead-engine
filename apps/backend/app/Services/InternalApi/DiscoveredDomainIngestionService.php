<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Enums\CrawlJobStatus;
use App\Enums\CrawlTriggerType;
use App\Models\CrawlJob;
use App\Models\Domain;
use App\Models\DomainSource;
use App\Enums\DomainStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DiscoveredDomainIngestionService
{
    public function __construct(
        private readonly DomainIngestionService $domainIngestionService,
    ) {
    }

    public function ingest(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $domain = $this->domainIngestionService->upsert([
                'domain' => $payload['domain'],
                'normalized_domain' => $payload['normalized_domain'] ?? null,
                'last_seen_at' => now(),
            ]);

            $source = DomainSource::query()->firstOrCreate(
                [
                    'domain_id' => $domain->id,
                    'source_type' => $payload['source_type'],
                    'source_name' => $payload['source_name'] ?? 'crawler_discovery',
                    'source_reference' => $payload['source_reference'] ?? null,
                ],
                [
                    'discovered_at' => now(),
                    'context' => $payload['source_context'] ?? null,
                ],
            );

            $jobs = [];
            if (Arr::get($payload, 'enqueue_homepage_fetch', true)) {
                $jobs['homepage_fetch'] = $this->createDiscoveryFollowUpJob(
                    $domain,
                    'homepage_fetch',
                    Arr::get($payload, 'priority_homepage_fetch', 3),
                );
            }

            if (Arr::get($payload, 'enqueue_page_classification', true)) {
                $jobs['page_classification'] = $this->createDiscoveryFollowUpJob(
                    $domain,
                    'page_classification',
                    Arr::get($payload, 'priority_page_classification', 5),
                );
            }

            return [
                'domain' => $domain->fresh(),
                'domain_source' => $source,
                'follow_up_jobs' => $jobs,
            ];
        });
    }

    private function createDiscoveryFollowUpJob(Domain $domain, string $jobType, int $priority): CrawlJob
    {
        /** @var CrawlJob|null $existing */
        $existing = CrawlJob::query()
            ->where('domain_id', $domain->id)
            ->whereIn('status', [
                CrawlJobStatus::Queued->value,
                CrawlJobStatus::Running->value,
            ])
            ->where('crawl_payload->job_type', $jobType)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $created = CrawlJob::query()->create([
            'domain_id' => $domain->id,
            'trigger_type' => CrawlTriggerType::Discovery,
            'priority' => $priority,
            'crawl_payload' => [
                'job_type' => $jobType,
                'domain' => $domain->normalized_domain,
            ],
        ]);

        $domain->update(['status' => DomainStatus::Queued]);

        return $created;
    }
}
