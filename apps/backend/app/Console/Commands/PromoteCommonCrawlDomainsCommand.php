<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DomainSourceType;
use App\Jobs\CalculateDomainConfidenceScoreJob;
use App\Jobs\CrawlHomepageJob;
use App\Jobs\GuessPlatformJob;
use App\Jobs\GuessProductCountJob;
use App\Models\Domain;
use App\Models\DomainSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PromoteCommonCrawlDomainsCommand extends Command
{
    protected $signature = 'common-crawl:promote-domains {--country=} {--all-countries} {--limit=1000} {--dry-run} {--chunk=500}';

    protected $description = 'Promote rows from common_crawl_domains into domains and dispatch follow-up jobs.';

    public function handle(): int
    {
        $country = $this->option('country');
        $allCountries = (bool) $this->option('all-countries');
        if (($country === null && ! $allCountries) || ($country !== null && $allCountries)) {
            $this->error('Provide exactly one of --country or --all-countries.');
            return self::INVALID;
        }

        $targetCountries = $country !== null
            ? [strtolower((string) $country)]
            : array_keys((array) config('common_crawl.countries', []));

        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        foreach ($targetCountries as $targetCountry) {
            $this->promoteCountry($targetCountry, $limit, $chunkSize, $dryRun);
        }

        return self::SUCCESS;
    }

    private function promoteCountry(string $country, int $limit, int $chunkSize, bool $dryRun): void
    {
        $query = DB::table('common_crawl_domains')
            ->whereRaw('LOWER(country) = ?', [$country])
            ->orderBy('id');

        $totalRows = (clone $query)->count();
        $this->info("Promoting country={$country} total_rows={$totalRows} limit={$limit} chunk={$chunkSize} dry_run=".($dryRun ? 'yes' : 'no'));

        $stats = [
            'processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped_duplicate' => 0, 'failed' => 0,
            'crawl_jobs' => 0, 'product_jobs' => 0, 'platform_jobs' => 0, 'confidence_jobs' => 0,
        ];

        $query->chunkById($chunkSize, function ($rows) use (&$stats, $limit, $dryRun): bool {
            foreach ($rows as $row) {
                if ($stats['processed'] >= $limit) {
                    return false;
                }

                $stats['processed']++;
                $normalized = $this->normalizeDomain((string) $row->domain);

                if ($normalized === null) {
                    $stats['failed']++;
                    $this->warn("Skipping row id={$row->id}: could not normalize domain={$row->domain}");
                    continue;
                }

                $existing = Domain::query()->where('normalized_domain', $normalized)->first();
                $isInsert = $existing === null;
                $needsRefresh = $isInsert || $existing->status->value === 'pending' || $existing->last_crawled_at === null;

                if (! $dryRun) {
                    $domain = $existing ?? new Domain();
                    $metadata = is_array($domain->metadata) ? $domain->metadata : [];
                    $metadata['common_crawl'] = [
                        'ecommerce_score' => (float) $row->ecommerce_score,
                        'crawl_id' => $row->crawl_id,
                        'source_url' => $row->source_url,
                        'last_seen_at' => $row->last_seen_at,
                    ];

                    $domain->fill([
                        'domain' => $normalized,
                        'normalized_domain' => $normalized,
                        'country' => $row->country,
                        'metadata' => $metadata,
                    ]);

                    if (array_key_exists('ecommerce_score', $domain->getAttributes())) {
                        $domain->setAttribute('ecommerce_score', $row->ecommerce_score);
                    }

                    $domain->save();

                    DomainSource::query()->firstOrCreate([
                        'domain_id' => $domain->id,
                        'source_type' => DomainSourceType::CommonCrawl->value,
                        'source_name' => 'common_crawl_domains',
                        'source_reference' => $row->source_url,
                    ], [
                        'discovered_at' => now(),
                        'context' => [
                            'crawl_id' => $row->crawl_id,
                            'matched_patterns' => json_decode((string) ($row->matched_patterns ?? '[]'), true),
                            'country' => $row->country,
                        ],
                    ]);

                    if ($isInsert) {
                        $stats['inserted']++;
                    } else {
                        $stats['updated']++;
                    }

                    if ($needsRefresh) {
                        CrawlHomepageJob::dispatch($normalized); $stats['crawl_jobs']++;
                        GuessProductCountJob::dispatch($normalized); $stats['product_jobs']++;
                        GuessPlatformJob::dispatch($normalized); $stats['platform_jobs']++;
                        CalculateDomainConfidenceScoreJob::dispatch($normalized); $stats['confidence_jobs']++;
                    } else {
                        $stats['skipped_duplicate']++;
                    }
                } else {
                    $action = $isInsert ? 'insert' : 'update';
                    $this->line("[dry-run] would {$action} domain={$normalized} country={$row->country}");
                }
            }

            return true;
        });

        $this->info(sprintf(
            'Summary country=%s processed=%d inserted=%d updated=%d skipped_duplicate=%d failed=%d dispatch(crawl=%d,product=%d,platform=%d,confidence=%d)',
            $country,
            $stats['processed'],
            $stats['inserted'],
            $stats['updated'],
            $stats['skipped_duplicate'],
            $stats['failed'],
            $stats['crawl_jobs'],
            $stats['product_jobs'],
            $stats['platform_jobs'],
            $stats['confidence_jobs'],
        ));
    }

    private function normalizeDomain(string $domain): ?string
    {
        $value = strtolower(trim($domain));
        $value = preg_replace('#^https?://#', '', $value);
        $value = preg_replace('#^www\.#', '', $value);
        $value = explode('/', (string) $value)[0] ?? '';

        return $value !== '' ? $value : null;
    }
}
