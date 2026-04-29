<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DomainStatus;
use App\Jobs\ProcessPendingDomainEnrichmentJob;
use App\Models\Domain;
use Illuminate\Console\Command;

class ProcessPendingDomainsCommand extends Command
{
    protected $signature = 'domains:process-pending {--country=} {--limit=500} {--chunk=100} {--dry-run}';

    protected $description = 'Process pending domains and dispatch domain enrichment pipeline.';

    public function handle(): int
    {
        $country = $this->option('country');
        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Domain::query()
            ->where('status', DomainStatus::Pending->value)
            ->when($country !== null, fn ($q) => $q->whereRaw('LOWER(country) = ?', [strtolower((string) $country)]))
            ->orderBy('id');

        $totalPending = (clone $query)->count();
        $this->info("Processing pending domains total_pending={$totalPending} limit={$limit} chunk={$chunkSize} dry_run=".($dryRun ? 'yes' : 'no'));

        $stats = ['dispatched' => 0, 'skipped' => 0, 'failed' => 0];

        $query->chunkById($chunkSize, function ($domains) use ($limit, $dryRun, &$stats): bool {
            foreach ($domains as $domain) {
                if (($stats['dispatched'] + $stats['skipped']) >= $limit) {
                    return false;
                }

                if ($dryRun) {
                    $stats['skipped']++;
                    $this->line("[dry-run] would process domain={$domain->normalized_domain} country={$domain->country}");
                    continue;
                }

                try {
                    $domain->forceFill(['status' => DomainStatus::Crawling])->save();
                    ProcessPendingDomainEnrichmentJob::dispatch($domain->normalized_domain);
                    $stats['dispatched']++;
                } catch (\Throwable $exception) {
                    $stats['failed']++;
                    $this->error("Failed to dispatch domain={$domain->normalized_domain}: {$exception->getMessage()}");
                }
            }

            return true;
        });

        $this->info(sprintf(
            'Summary total_pending=%d dispatched=%d skipped=%d failed=%d',
            $totalPending,
            $stats['dispatched'],
            $stats['skipped'],
            $stats['failed'],
        ));

        return self::SUCCESS;
    }
}
