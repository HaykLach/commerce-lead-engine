<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DomainStatus;
use App\Models\CrawlJob;
use App\Models\Domain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateCrawlJobsCommand extends Command
{
    private const JOB_TYPES = [
        'crawl_homepage',
        'detect_platform',
        'collect_metrics',
        'guess_niche',
        'calculate_confidence',
    ];

    protected $signature = 'domains:create-crawl-jobs {--country=de} {--limit=500} {--chunk=100} {--dry-run}';

    protected $description = 'Create crawl_jobs for pending domains to be consumed by the Python worker.';

    public function handle(): int
    {
        $country = (string) $this->option('country');
        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Domain::query()
            ->where('status', DomainStatus::Pending->value)
            ->when($country !== '', fn ($q) => $q->whereRaw('LOWER(country) = ?', [strtolower($country)]))
            ->orderBy('id');

        $totalFound = (clone $query)->count();
        $this->info("domains found={$totalFound} limit={$limit} chunk={$chunkSize} dry_run=".($dryRun ? 'yes' : 'no'));

        $stats = [
            'jobs_inserted' => 0,
            'duplicates_skipped' => 0,
            'domains_queued' => 0,
            'domains_considered' => 0,
        ];

        $query->chunkById($chunkSize, function ($domains) use (&$stats, $limit, $dryRun): bool {
            foreach ($domains as $domain) {
                if ($stats['domains_considered'] >= $limit) {
                    return false;
                }

                $stats['domains_considered']++;

                $insertedForDomain = 0;
                $duplicatesForDomain = 0;

                foreach (self::JOB_TYPES as $jobType) {
                    $alreadyExists = CrawlJob::query()
                        ->where('domain_id', $domain->id)
                        ->whereIn('status', ['pending', 'processing', 'completed'])
                        ->where('crawl_payload->job_type', $jobType)
                        ->exists();

                    if ($alreadyExists) {
                        $duplicatesForDomain++;
                        $stats['duplicates_skipped']++;
                        continue;
                    }

                    if ($dryRun) {
                        $insertedForDomain++;
                        continue;
                    }

                    CrawlJob::query()->create([
                        'domain_id' => $domain->id,
                        'status' => 'pending',
                        'trigger_type' => 'discovery',
                        'crawl_payload' => [
                            'job_type' => $jobType,
                            'domain' => $domain->normalized_domain,
                            'country' => $domain->country,
                            'source' => 'common_crawl',
                        ],
                    ]);
                    $insertedForDomain++;
                    $stats['jobs_inserted']++;
                }

                if ($dryRun) {
                    $this->line("[dry-run] domain={$domain->normalized_domain} jobs_to_insert={$insertedForDomain} duplicate_jobs_skipped={$duplicatesForDomain} would_mark_queued=".($insertedForDomain > 0 ? 'yes' : 'no'));
                    continue;
                }

                if ($insertedForDomain > 0) {
                    DB::table('domains')
                        ->where('id', $domain->id)
                        ->where('status', DomainStatus::Pending->value)
                        ->update(['status' => DomainStatus::Queued->value, 'updated_at' => now()]);

                    $stats['domains_queued']++;
                }
            }

            return true;
        });

        $this->info("jobs inserted={$stats['jobs_inserted']}");
        $this->info("duplicate jobs skipped={$stats['duplicates_skipped']}");
        $this->info("domains marked queued={$stats['domains_queued']}");

        return self::SUCCESS;
    }
}
