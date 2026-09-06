<?php

declare(strict_types=1);

namespace App\Services\PageSpeed;

use App\Jobs\RunPageSpeedMeasurement;
use App\Models\PageSpeedMeasurement;
use App\Models\WebsiteAudit;
use App\Models\WebsiteAuditPage;
use App\Services\Enrichment\AuditUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PageSpeedDispatcher
{
    public function assertConfigured(): void
    {
        $connection = config('pagespeed.connection');
        if (! config('pagespeed.enabled')) {
            throw ValidationException::withMessages(['pagespeed' => 'Enable PageSpeed integration in the server configuration first.']);
        }
        if (! in_array(config("queue.connections.{$connection}.driver"), ['database', 'redis'], true)
            || (int) config("queue.connections.{$connection}.retry_after") <= 85) {
            throw ValidationException::withMessages(['pagespeed' => 'PageSpeed requires a database or Redis queue with retry_after greater than 85 seconds.']);
        }
    }

    public function strategies(): array
    {
        return config('pagespeed.desktop') ? ['mobile', 'desktop'] : ['mobile'];
    }

    public function eligiblePages(): Builder
    {
        return WebsiteAuditPage::query()->where('status', 'completed')->whereIn('kind', ['homepage', 'category'])
            ->whereHas('audit', fn (Builder $query) => $query->whereIn('status', ['completed', 'partial'])
                ->whereNotExists(fn ($newer) => $newer->selectRaw('1')->from('website_audits as newer')
                    ->whereColumn('newer.domain_id', 'website_audits.domain_id')->whereColumn('newer.id', '>', 'website_audits.id')));
    }

    public function request(WebsiteAudit $audit, bool $refresh = false): int
    {
        $this->assertConfigured();
        if (! in_array($audit->status, ['completed', 'partial'], true)) {
            throw ValidationException::withMessages(['pagespeed' => 'Complete the website audit before measuring performance.']);
        }
        $created = 0;
        foreach ($audit->pages()->where('status', 'completed')->whereIn('kind', ['homepage', 'category'])->limit(2)->get() as $page) {
            foreach ($this->strategies() as $strategy) {
                $created += $this->requestPage($page, $strategy, $refresh)->wasRecentlyCreated ? 1 : 0;
            }
        }

        return $created;
    }

    public function requestPage(WebsiteAuditPage $page, string $strategy, bool $refresh = false): PageSpeedMeasurement
    {
        $this->assertConfigured();

        return DB::transaction(function () use ($page, $strategy, $refresh): PageSpeedMeasurement {
            $page = WebsiteAuditPage::query()->lockForUpdate()->findOrFail($page->id);
            if ($page->status !== 'completed' || ! in_array($page->kind, ['homepage', 'category'], true)
                || ! in_array($page->audit->status, ['completed', 'partial'], true) || ! in_array($strategy, $this->strategies(), true)) {
                throw ValidationException::withMessages(['pagespeed' => 'The selected page is not eligible for measurement.']);
            }
            $active = $page->pageSpeedMeasurements()->where('strategy', $strategy)->whereNotNull('active_key')->first();
            if ($active !== null) {
                return $active;
            }
            if (! $refresh) {
                $fresh = $page->pageSpeedMeasurements()->where('strategy', $strategy)->where('expires_at', '>', now())->latest('id')->first();
                if ($fresh !== null) {
                    return $fresh;
                }
            }
            $url = AuditUrl::normalize($page->final_url ?? $page->url);
            if ($url === null || ! AuditUrl::sameSite($url, $page->audit->domain->normalized_domain)) {
                throw ValidationException::withMessages(['pagespeed' => 'The audited page URL is not valid for this domain.']);
            }
            $measurement = $page->pageSpeedMeasurements()->create(['strategy' => $strategy, 'active_key' => $page->id.':'.$strategy,
                'requested_url' => $url, 'status' => 'queued', 'next_attempt_at' => now()]);
            $this->enqueue($measurement->id);

            return $measurement;
        }, 3);
    }

    public function enqueue(int $id): void
    {
        RunPageSpeedMeasurement::dispatch($id)->onConnection(config('pagespeed.connection'))->onQueue(config('pagespeed.queue'))->afterCommit();
    }

    public function recover(int $limit): int
    {
        $ids = PageSpeedMeasurement::query()->whereNotNull('active_key')->where('updated_at', '<', now()->subMinutes(5))
            ->where(fn ($q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', now()))
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $measurement = PageSpeedMeasurement::query()->lockForUpdate()->find($id);
                if ($measurement === null || $measurement->active_key === null || $measurement->lease_until?->isFuture()
                    || $measurement->next_attempt_at?->isFuture() || $measurement->updated_at->gt(now()->subMinutes(5))) {
                    return 0;
                }
                $measurement->touch();
                $this->enqueue($id);

                return 1;
            });
        }

        return $count;
    }
}
