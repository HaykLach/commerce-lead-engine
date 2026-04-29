<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\DomainStatus;
use App\Jobs\ProcessPendingDomainEnrichmentJob;
use App\Models\Domain;
use App\Services\Domain\DomainEnrichmentPipeline;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProcessPendingDomainsCommandTest extends TestCase
{
    public function test_it_marks_domains_as_processing_and_dispatches_jobs(): void
    {
        Bus::fake();

        $domain = Domain::factory()->create([
            'normalized_domain' => 'alpha.example',
            'domain' => 'alpha.example',
            'status' => DomainStatus::Pending,
        ]);

        Artisan::call('domains:process-pending', ['--limit' => 10, '--chunk' => 5]);

        $domain->refresh();
        $this->assertSame(DomainStatus::Crawling, $domain->status);

        Bus::assertDispatched(ProcessPendingDomainEnrichmentJob::class, function ($job) {
            return $job->domain === 'alpha.example';
        });
    }

    public function test_it_applies_country_filter(): void
    {
        Bus::fake();

        $deDomain = Domain::factory()->create(['normalized_domain' => 'de.example', 'domain' => 'de.example', 'country' => 'DE', 'status' => DomainStatus::Pending]);
        $usDomain = Domain::factory()->create(['normalized_domain' => 'us.example', 'domain' => 'us.example', 'country' => 'US', 'status' => DomainStatus::Pending]);

        Artisan::call('domains:process-pending', ['--country' => 'de']);

        $deDomain->refresh();
        $usDomain->refresh();

        $this->assertSame(DomainStatus::Crawling, $deDomain->status);
        $this->assertSame(DomainStatus::Pending, $usDomain->status);
    }

    public function test_dry_run_does_not_dispatch_or_change_status(): void
    {
        Bus::fake();

        $domain = Domain::factory()->create([
            'normalized_domain' => 'dry.example',
            'domain' => 'dry.example',
            'status' => DomainStatus::Pending,
        ]);

        Artisan::call('domains:process-pending', ['--dry-run' => true]);

        $domain->refresh();
        $this->assertSame(DomainStatus::Pending, $domain->status);
        Bus::assertNotDispatched(ProcessPendingDomainEnrichmentJob::class);
    }

    public function test_enrichment_job_marks_processed_on_success(): void
    {
        $domain = Domain::factory()->create([
            'normalized_domain' => 'processed.example',
            'domain' => 'processed.example',
            'status' => DomainStatus::Crawling,
        ]);

        $job = new ProcessPendingDomainEnrichmentJob('processed.example');
        $job->handle(app(DomainEnrichmentPipeline::class));

        $domain->refresh();
        $this->assertSame(DomainStatus::Processed, $domain->status);
        $this->assertDatabaseHas('domain_metrics', ['domain_id' => $domain->id]);
    }

    public function test_enrichment_job_marks_failed_and_stores_error(): void
    {
        $domain = Domain::factory()->create([
            'normalized_domain' => 'failed.example',
            'domain' => 'failed.example',
            'status' => DomainStatus::Crawling,
            'metadata' => [],
        ]);

        $failingPipeline = new class extends DomainEnrichmentPipeline {
            public function run(Domain $domain): void
            {
                throw new \RuntimeException('pipeline exploded');
            }
        };

        $job = new ProcessPendingDomainEnrichmentJob('failed.example');

        try {
            $job->handle($failingPipeline);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('pipeline exploded', $exception->getMessage());
        }

        $domain->refresh();
        $this->assertSame(DomainStatus::Failed, $domain->status);
        $this->assertSame('pipeline exploded', $domain->metadata['processing_error'] ?? null);
    }
}
