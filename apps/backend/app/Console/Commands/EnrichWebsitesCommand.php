<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\WebsiteAudit;
use App\Services\Enrichment\WebsiteAuditDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnrichWebsitesCommand extends Command
{
    protected $signature = 'websites:enrich {--domain-id=} {--limit=25} {--refresh : Start a fresh audit for the specified domain}';

    protected $description = 'Queue website audits for accepted ecommerce domains, reusing fresh results';

    public function handle(WebsiteAuditDispatcher $dispatcher): int
    {
        $domainId = $this->option('domain-id');
        $limit = $this->option('limit');
        if (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100
            || ($domainId !== null && (! ctype_digit((string) $domainId) || (int) $domainId < 1))
            || ($this->option('refresh') && $domainId === null)) {
            $this->error('Use a limit from 1 to 100, a positive domain ID, and --refresh only with --domain-id.');

            return self::FAILURE;
        }
        if ($domainId !== null) {
            $domain = Domain::query()->find($domainId);
            if ($domain === null) {
                $this->error('Domain not found.');

                return self::FAILURE;
            }
            $audit = $dispatcher->request($domain, (bool) $this->option('refresh'));
            $this->info("Audit #{$audit->id}: {$audit->status}");

            return self::SUCCESS;
        }
        $queued = 0;
        // Recover work lost between DB persistence and queue publication or interrupted workers.
        $stale = WebsiteAudit::query()->whereNotNull('active_domain_id')->where('updated_at', '<', now()->subMinutes(5))
            ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<', now()))->limit((int) $limit)->pluck('id');
        foreach ($stale as $id) {
            $queued += DB::transaction(function () use ($id, $dispatcher): int {
                $audit = WebsiteAudit::query()->lockForUpdate()->find($id);
                if ($audit === null || $audit->active_domain_id === null || $audit->lease_until?->isFuture() || $audit->updated_at->gt(now()->subMinutes(5))) {
                    return 0;
                }
                $audit->touch();
                $dispatcher->enqueue($id);

                return 1;
            });
        }
        if ($queued < (int) $limit) {
            $domains = Domain::query()->where('metadata->site_acceptable', true)
                ->whereDoesntHave('websiteAudits', fn ($query) => $query->whereNotNull('active_domain_id')->orWhere('expires_at', '>', now()))
                ->orderBy('id')->limit((int) $limit - $queued)->get();
            foreach ($domains as $domain) {
                $audit = $dispatcher->request($domain);
                $queued += $audit->wasRecentlyCreated ? 1 : 0;
            }
        }
        $this->info("Queued or recovered {$queued} website audits.");

        return self::SUCCESS;
    }
}
