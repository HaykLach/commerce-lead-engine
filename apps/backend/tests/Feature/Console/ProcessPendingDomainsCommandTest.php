<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\DomainStatus;
use App\Models\CrawlJob;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProcessPendingDomainsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_domain_creates_crawl_jobs(): void
    {
        $domain = Domain::factory()->create([
            'normalized_domain' => 'alpha.example',
            'domain' => 'alpha.example',
            'country' => 'DE',
            'status' => DomainStatus::Pending,
        ]);

        Artisan::call('domains:create-crawl-jobs', ['--country' => 'de', '--limit' => 10, '--chunk' => 5]);

        $this->assertSame(5, CrawlJob::query()->where('domain_id', $domain->id)->count());
        $this->assertDatabaseHas('crawl_jobs', [
            'domain_id' => $domain->id,
            'status' => 'pending',
        ]);
    }

    public function test_country_filter_works(): void
    {
        $deDomain = Domain::factory()->create(['normalized_domain' => 'de.example', 'domain' => 'de.example', 'country' => 'DE', 'status' => DomainStatus::Pending]);
        $usDomain = Domain::factory()->create(['normalized_domain' => 'us.example', 'domain' => 'us.example', 'country' => 'US', 'status' => DomainStatus::Pending]);

        Artisan::call('domains:create-crawl-jobs', ['--country' => 'de']);

        $this->assertSame(5, CrawlJob::query()->where('domain_id', $deDomain->id)->count());
        $this->assertSame(0, CrawlJob::query()->where('domain_id', $usDomain->id)->count());
    }

    public function test_dry_run_does_not_insert(): void
    {
        $domain = Domain::factory()->create([
            'normalized_domain' => 'dry.example',
            'domain' => 'dry.example',
            'country' => 'DE',
            'status' => DomainStatus::Pending,
        ]);

        Artisan::call('domains:create-crawl-jobs', ['--dry-run' => true, '--country' => 'de']);

        $this->assertSame(0, CrawlJob::query()->where('domain_id', $domain->id)->count());
    }

    public function test_duplicate_crawl_jobs_are_not_created(): void
    {
        $domain = Domain::factory()->create([
            'normalized_domain' => 'dup.example',
            'domain' => 'dup.example',
            'country' => 'DE',
            'status' => DomainStatus::Pending,
        ]);

        CrawlJob::query()->create([
            'domain_id' => $domain->id,
            'status' => 'completed',
            'trigger_type' => 'discovery',
            'crawl_payload' => [
                'job_type' => 'crawl_homepage',
                'domain' => 'dup.example',
                'country' => 'DE',
                'source' => 'common_crawl',
            ],
        ]);

        Artisan::call('domains:create-crawl-jobs', ['--country' => 'de']);

        $this->assertSame(5, CrawlJob::query()->where('domain_id', $domain->id)->count());
        $this->assertSame(1, CrawlJob::query()->where('domain_id', $domain->id)->where('crawl_payload->job_type', 'crawl_homepage')->count());
    }

    public function test_domain_status_becomes_queued_after_jobs_are_created(): void
    {
        $domain = Domain::factory()->create([
            'normalized_domain' => 'queue.example',
            'domain' => 'queue.example',
            'country' => 'DE',
            'status' => DomainStatus::Pending,
        ]);

        Artisan::call('domains:create-crawl-jobs', ['--country' => 'de']);

        $domain->refresh();
        $this->assertSame(DomainStatus::Queued, $domain->status);
    }
}
