<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Enums\CrawlJobStatus;
use App\Enums\CrawlTriggerType;
use App\Models\CrawlJob;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrawlJobControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_next_returns_204_when_no_queued_jobs(): void
    {
        CrawlJob::factory()->create([
            'status' => CrawlJobStatus::Running,
        ]);

        $this->getJson('/api/v1/internal/crawl-jobs/next')->assertNoContent();
    }

    public function test_next_returns_the_next_queued_job_when_available(): void
    {
        CrawlJob::factory()->create([
            'status' => CrawlJobStatus::Queued,
            'priority' => 5,
            'scheduled_at' => now()->addMinute(),
        ]);

        $expectedJob = CrawlJob::factory()->create([
            'status' => CrawlJobStatus::Queued,
            'priority' => 1,
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/internal/crawl-jobs/next')
            ->assertOk()
            ->assertJsonPath('data.id', $expectedJob->id)
            ->assertJsonPath('data.status', CrawlJobStatus::Queued->value)
            ->assertJsonPath('data.trigger_type', CrawlTriggerType::Discovery->value);
    }

    public function test_start_updates_status_and_started_at(): void
    {
        $crawlJob = CrawlJob::factory()->create([
            'status' => CrawlJobStatus::Queued,
            'started_at' => null,
        ]);

        $this->postJson("/api/v1/internal/crawl-jobs/{$crawlJob->id}/start")
            ->assertOk()
            ->assertJsonPath('data.status', CrawlJobStatus::Running->value);

        $crawlJob->refresh();

        $this->assertSame(CrawlJobStatus::Running, $crawlJob->status);
        $this->assertNotNull($crawlJob->started_at);
    }

    public function test_complete_updates_status_finished_at_and_summary(): void
    {
        $domain = Domain::factory()->create([
            'last_crawled_at' => null,
            'last_seen_at' => null,
        ]);

        $crawlJob = CrawlJob::factory()->create([
            'domain_id' => $domain->id,
            'status' => CrawlJobStatus::Running,
            'finished_at' => null,
            'crawl_summary' => null,
        ]);

        $summary = [
            'domain' => 'example.com',
            'pages_crawled' => 4,
        ];

        $this->postJson("/api/v1/internal/crawl-jobs/{$crawlJob->id}/complete", [
            'summary' => $summary,
        ])->assertOk()
            ->assertJsonPath('data.status', CrawlJobStatus::Completed->value)
            ->assertJsonPath('data.crawl_summary.pages_crawled', 4);

        $crawlJob->refresh();
        $domain->refresh();

        $this->assertSame(CrawlJobStatus::Completed, $crawlJob->status);
        $this->assertNotNull($crawlJob->finished_at);
        $this->assertSame($summary, $crawlJob->crawl_summary);
        $this->assertNotNull($domain->last_crawled_at);
        $this->assertNotNull($domain->last_seen_at);
    }

    public function test_fail_updates_status_finished_at_and_failure_reason(): void
    {
        $crawlJob = CrawlJob::factory()->create([
            'status' => CrawlJobStatus::Running,
            'finished_at' => null,
            'failure_reason' => null,
        ]);

        $errorMessage = 'Connection timeout while crawling homepage';

        $this->postJson("/api/v1/internal/crawl-jobs/{$crawlJob->id}/fail", [
            'error' => $errorMessage,
        ])->assertOk()
            ->assertJsonPath('data.status', CrawlJobStatus::Failed->value)
            ->assertJsonPath('data.failure_reason', $errorMessage);

        $crawlJob->refresh();

        $this->assertSame(CrawlJobStatus::Failed, $crawlJob->status);
        $this->assertNotNull($crawlJob->finished_at);
        $this->assertSame($errorMessage, $crawlJob->failure_reason);
    }

    public function test_store_rejects_worker_stage_as_trigger_type(): void
    {
        $domain = Domain::factory()->create();

        $this->postJson('/api/v1/internal/crawl-jobs', [
            'domain_id' => $domain->id,
            'trigger_type' => 'homepage_fetch',
            'crawl_payload' => [
                'domain' => $domain->domain,
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['trigger_type', 'crawl_payload.job_type']);
    }

    public function test_store_accepts_trigger_type_metadata_with_payload_job_type(): void
    {
        $domain = Domain::factory()->create();

        $response = $this->postJson('/api/v1/internal/crawl-jobs', [
            'domain_id' => $domain->id,
            'trigger_type' => CrawlTriggerType::Manual->value,
            'crawl_payload' => [
                'job_type' => 'homepage_fetch',
                'domain' => $domain->domain,
            ],
        ])->assertCreated()
            ->assertJsonPath('data.trigger_type', CrawlTriggerType::Manual->value)
            ->assertJsonPath('data.crawl_payload.job_type', 'homepage_fetch');

        $crawlJobId = $response->json('data.id');
        $crawlJob = CrawlJob::query()->findOrFail($crawlJobId);

        $this->assertSame(CrawlTriggerType::Manual, $crawlJob->trigger_type);
        $this->assertSame('homepage_fetch', $crawlJob->crawl_payload['job_type']);
    }
}
