<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Jobs\CalculateDomainConfidenceScoreJob;
use App\Jobs\CrawlHomepageJob;
use App\Jobs\GuessPlatformJob;
use App\Jobs\GuessProductCountJob;
use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PromoteCommonCrawlDomainsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_is_copied_and_jobs_dispatched(): void
    {
        Bus::fake();
        DB::table('common_crawl_domains')->insert([
            'domain' => 'www.Example.de', 'tld' => 'de', 'country' => 'de', 'ecommerce_score' => 0.92,
            'matched_patterns' => json_encode(['/product/']), 'source_url' => 'https://www.example.de/product/1', 'crawl_id' => 'CC',
            'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('common-crawl:promote-domains --country=de --limit=100 --chunk=10')->assertSuccessful();

        $this->assertDatabaseHas('domains', ['normalized_domain' => 'example.de', 'country' => 'de']);
        Bus::assertDispatched(CrawlHomepageJob::class);
        Bus::assertDispatched(GuessProductCountJob::class);
        Bus::assertDispatched(GuessPlatformJob::class);
        Bus::assertDispatched(CalculateDomainConfidenceScoreJob::class);
    }

    public function test_processes_large_chunked_dataset(): void
    {
        for ($i = 1; $i <= 3000; $i++) {
            DB::table('common_crawl_domains')->insert([
                'domain' => "shop{$i}.de", 'tld' => 'de', 'country' => 'de', 'ecommerce_score' => 0.5,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('common-crawl:promote-domains --country=de --limit=3000 --chunk=500')->assertSuccessful();

        $this->assertSame(3000, Domain::query()->count());
    }

    public function test_dry_run_does_not_write_or_dispatch(): void
    {
        Bus::fake();
        DB::table('common_crawl_domains')->insert([
            'domain' => 'dryrun.de', 'tld' => 'de', 'country' => 'de', 'ecommerce_score' => 0.3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('common-crawl:promote-domains --country=de --dry-run')->assertSuccessful();

        $this->assertSame(0, Domain::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_duplicate_domains_are_updated_once(): void
    {
        DB::table('common_crawl_domains')->insert([
            ['domain' => 'dup.de', 'tld' => 'de', 'country' => 'de', 'ecommerce_score' => 0.2, 'created_at' => now(), 'updated_at' => now()],
            ['domain' => 'www.dup.de', 'tld' => 'de', 'country' => 'de', 'ecommerce_score' => 0.7, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('common-crawl:promote-domains --country=de --limit=10')->assertSuccessful();

        $this->assertSame(1, Domain::query()->where('normalized_domain', 'dup.de')->count());
        $this->assertDatabaseHas('domains', ['normalized_domain' => 'dup.de', 'country' => 'de']);
    }

    public function test_all_countries_uses_configured_countries_and_country_filter_works(): void
    {
        DB::table('common_crawl_domains')->insert([
            ['domain' => 'a.de', 'tld' => 'de', 'country' => 'de', 'ecommerce_score' => 0.2, 'created_at' => now(), 'updated_at' => now()],
            ['domain' => 'b.fr', 'tld' => 'fr', 'country' => 'fr', 'ecommerce_score' => 0.3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('common-crawl:promote-domains --country=de --limit=100')->assertSuccessful();
        $this->assertSame(1, Domain::query()->count());

        Domain::query()->delete();

        $this->artisan('common-crawl:promote-domains --all-countries --limit=100')->assertSuccessful();
        $this->assertSame(2, Domain::query()->count());
    }
}
