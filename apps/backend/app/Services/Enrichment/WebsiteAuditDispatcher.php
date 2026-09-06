<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Jobs\RunWebsiteAudit;
use App\Models\Domain;
use App\Models\WebsiteAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WebsiteAuditDispatcher
{
    public function request(Domain $domain, bool $refresh = false): WebsiteAudit
    {
        return DB::transaction(function () use ($domain, $refresh): WebsiteAudit {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $active = WebsiteAudit::query()->where('active_domain_id', $domain->id)->first();
            if ($active !== null) {
                return $active;
            }
            if (! $refresh) {
                $fresh = $domain->websiteAudits()->where('expires_at', '>', now())->latest('id')->first();
                if ($fresh !== null) {
                    return $fresh;
                }
            }
            $url = AuditUrl::normalize((string) data_get($domain->metadata, 'final_url', ''));
            if ($url === null || ! AuditUrl::sameSite($url, $domain->normalized_domain)) {
                $url = AuditUrl::normalize('https://'.$domain->normalized_domain.'/');
            }
            if ($url === null) {
                throw ValidationException::withMessages(['audit' => 'This domain does not have a valid website URL.']);
            }
            $audit = $domain->websiteAudits()->create(['active_domain_id' => $domain->id, 'status' => 'queued']);
            $audit->pages()->create(['url' => $url, 'url_hash' => hash('sha256', $url), 'kind' => 'homepage']);
            $this->enqueue($audit->id);

            return $audit;
        }, 3);
    }

    public function enqueue(int $auditId): void
    {
        $connection = config('enrichment.connection');
        if (! in_array(config("queue.connections.{$connection}.driver"), ['database', 'redis'], true)) {
            throw ValidationException::withMessages(['audit' => 'Website enrichment requires a database or Redis queue connection.']);
        }
        RunWebsiteAudit::dispatch($auditId)->onConnection($connection)->onQueue(config('enrichment.queue'))->afterCommit();
    }
}
