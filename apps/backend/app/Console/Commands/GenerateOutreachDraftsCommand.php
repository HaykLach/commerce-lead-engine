<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\Outreach\DraftDispatcher;
use App\Services\Outreach\DraftException;
use Illuminate\Console\Command;

class GenerateOutreachDraftsCommand extends Command
{
    protected $signature = 'outreach:draft {--domain-id=} {--limit=25} {--regenerate : Create a new version for the specified domain}';

    protected $description = 'Queue email drafts from saved audit evidence and selected contacts';

    public function handle(DraftDispatcher $dispatcher): int
    {
        $id = $this->option('domain-id');
        $limit = $this->option('limit');
        if (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100
            || ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) || ($this->option('regenerate') && $id === null)) {
            $this->error('Use a limit from 1 to 100, a positive domain ID, and --regenerate only with --domain-id.');

            return self::FAILURE;
        }
        try {
            $dispatcher->assertConfigured();
            if ($id !== null) {
                $domain = Domain::query()->find($id);
                if ($domain === null) {
                    $this->error('Domain not found.');

                    return self::FAILURE;
                }
                $draft = $dispatcher->request($domain, (bool) $this->option('regenerate'));
                $this->info("Draft #{$draft->id}: {$draft->status}");

                return self::SUCCESS;
            }
            $count = $dispatcher->recover((int) $limit);
            // One automatic draft per audit/recipient. Explicit regeneration can use later PageSpeed data.
            $domains = Domain::query()->whereNotNull('primary_contact_id')
                ->whereHas('primaryContact', fn ($q) => $q->where('review_status', '!=', 'excluded'))
                ->whereHas('latestWebsiteAudit', fn ($q) => $q->whereIn('status', ['completed', 'partial'])->where('expires_at', '>', now()))
                ->whereDoesntHave('outreachDrafts', fn ($q) => $q->whereNotNull('active_domain_id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('outreach_drafts')
                    ->whereColumn('outreach_drafts.domain_id', 'domains.id')->whereColumn('outreach_drafts.domain_contact_id', 'domains.primary_contact_id')
                    ->where('outreach_drafts.website_audit_id', '=', function ($q): void {
                        $q->selectRaw('MAX(website_audits.id)')->from('website_audits')->whereColumn('website_audits.domain_id', 'domains.id');
                    }))->orderBy('id');
            foreach ($domains->lazyById(100) as $domain) {
                if ($count >= (int) $limit) {
                    break;
                }
                try {
                    $draft = $dispatcher->request($domain);
                    $count += $draft->wasRecentlyCreated ? 1 : 0;
                } catch (DraftException $exception) {
                    $this->warn("Domain #{$domain->id}: {$exception->getMessage()}");
                }
            }
            $this->info("Created or recovered {$count} draft records.");
        } catch (DraftException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
