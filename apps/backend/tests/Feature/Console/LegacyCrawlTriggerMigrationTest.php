<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegacyCrawlTriggerMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_trigger_conversion_preserves_payload_and_rolls_back(): void
    {
        $domain = Domain::factory()->create();
        $homepageId = DB::table('crawl_jobs')->insertGetId([
            'domain_id' => $domain->id, 'trigger_type' => 'homepage_fetch',
            'crawl_payload' => json_encode(['domain' => $domain->domain]),
        ]);
        $classificationId = DB::table('crawl_jobs')->insertGetId([
            'domain_id' => $domain->id, 'trigger_type' => 'homepage_fetch',
            'crawl_payload' => json_encode(['job_type' => 'page_classification']),
        ]);
        $migration = require database_path('migrations/2026_04_18_000010_normalize_legacy_homepage_fetch_trigger_types.php');
        $migration->up();
        $homepage = DB::table('crawl_jobs')->find($homepageId);
        $this->assertSame('manual', $homepage->trigger_type);
        $this->assertSame(['domain' => $domain->domain, 'job_type' => 'homepage_fetch'], json_decode($homepage->crawl_payload, true));
        $this->assertSame('page_classification', json_decode(DB::table('crawl_jobs')->find($classificationId)->crawl_payload, true)['job_type']);
        $migration->down();
        $this->assertSame('homepage_fetch', DB::table('crawl_jobs')->find($homepageId)->trigger_type);
        $this->assertSame('manual', DB::table('crawl_jobs')->find($classificationId)->trigger_type);
    }
}
