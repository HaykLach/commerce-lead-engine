<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PageClassification;
use App\Services\Contacts\ContactIngestionService;
use Illuminate\Console\Command;

class ImportDomainContactsCommand extends Command
{
    protected $signature = 'contacts:import {--domain-id= : Import only one domain}';

    protected $description = 'Import saved contact evidence without crawling websites; safe to repeat';

    public function handle(ContactIngestionService $ingestion): int
    {
        $domainId = $this->option('domain-id');
        if ($domainId !== null && (! ctype_digit((string) $domainId) || (int) $domainId < 1)) {
            $this->error('The domain ID must be a positive integer.');

            return self::FAILURE;
        }
        $processed = 0;
        PageClassification::query()->when($domainId !== null, fn ($query) => $query->where('domain_id', $domainId))
            ->chunkById(100, function ($records) use ($ingestion, &$processed): void {
                foreach ($records as $record) {
                    $ingestion->ingest($record);
                    $processed++;
                }
            });
        $this->info("Processed {$processed} saved classifications.");

        return self::SUCCESS;
    }
}
