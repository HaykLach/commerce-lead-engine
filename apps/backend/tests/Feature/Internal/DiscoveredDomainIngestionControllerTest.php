<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Enums\CrawlJobStatus;
use App\Models\CrawlJob;
use App\Models\Domain;
use App\Models\DomainSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveredDomainIngestionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_creates_domain_source_and_follow_up_jobs(): void
    {
        $response = $this->postJson('/api/v1/internal/discovered-domains/ingest', [
            'domain' => 'https://www.Example.com',
            'source_type' => 'search_seed',
            'source_name' => 'keyword_seed',
            'source_reference' => 'https://results.example.test?q=fashion+de',
            'source_context' => [
                'keyword_seed' => 'fashion de shop',
            ],
            'priority_homepage_fetch' => 2,
            'priority_page_classification' => 4,
        ])->assertCreated();

        $domainId = $response->json('data.domain.id');

        $this->assertDatabaseHas('domains', [
            'id' => $domainId,
            'normalized_domain' => 'example.com',
        ]);

        $this->assertDatabaseHas('domain_sources', [
            'domain_id' => $domainId,
            'source_type' => 'search_seed',
            'source_name' => 'keyword_seed',
        ]);

        $this->assertDatabaseHas('crawl_jobs', [
            'domain_id' => $domainId,
            'status' => CrawlJobStatus::Queued->value,
            'trigger_type' => 'discovery',
        ]);

        $this->assertSame('homepage_fetch', $response->json('data.follow_up_jobs.homepage_fetch.crawl_payload.job_type'));
        $this->assertSame('page_classification', $response->json('data.follow_up_jobs.page_classification.crawl_payload.job_type'));
    }

    public function test_ingest_avoids_duplicate_source_and_follow_up_jobs(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
        ]);

        DomainSource::query()->create([
            'domain_id' => $domain->id,
            'source_type' => 'directory',
            'source_name' => 'directory_listing',
            'source_reference' => 'https://dir.example.test/stores',
        ]);

        CrawlJob::factory()->create([
            'domain_id' => $domain->id,
            'status' => CrawlJobStatus::Queued,
            'crawl_payload' => [
                'job_type' => 'homepage_fetch',
                'domain' => 'example.com',
            ],
        ]);

        $this->postJson('/api/v1/internal/discovered-domains/ingest', [
            'domain' => 'example.com',
            'source_type' => 'directory',
            'source_name' => 'directory_listing',
            'source_reference' => 'https://dir.example.test/stores',
        ])->assertCreated();

        $this->assertSame(1, DomainSource::query()->count());

        $homepageJobs = CrawlJob::query()->where('domain_id', $domain->id)->where('crawl_payload->job_type', 'homepage_fetch')->count();
        $classificationJobs = CrawlJob::query()->where('domain_id', $domain->id)->where('crawl_payload->job_type', 'page_classification')->count();

        $this->assertSame(1, $homepageJobs);
        $this->assertSame(1, $classificationJobs);
    }

    public function test_ingest_accepts_common_crawl_source_type_and_context_metadata(): void
    {
        $response = $this->postJson('/api/v1/internal/discovered-domains/ingest', [
            'domain' => 'tiny-shop.de',
            'source_type' => 'common_crawl',
            'source_name' => 'common_crawl_discovery',
            'source_reference' => 'https://tiny-shop.de/products/item-1',
            'source_context' => [
                'matched_pattern' => '/products/',
                'backend' => 'duckdb',
                'crawl' => [
                    'crawl' => 'CC-MAIN-2026-01',
                ],
            ],
        ])->assertCreated();

        $domainId = $response->json('data.domain.id');

        $this->assertDatabaseHas('domain_sources', [
            'domain_id' => $domainId,
            'source_type' => 'common_crawl',
            'source_name' => 'common_crawl_discovery',
            'source_reference' => 'https://tiny-shop.de/products/item-1',
        ]);

        $source = DomainSource::query()->where('domain_id', $domainId)->firstOrFail();
        $this->assertSame('/products/', $source->context['matched_pattern']);
        $this->assertSame('duckdb', $source->context['backend']);
    }

}
