<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Models\PageSpeedMeasurement;
use App\Services\PageSpeed\PageSpeedDispatcher;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DomainPageSpeed extends Component
{
    #[Locked]
    public int $domainId;

    public function mount(int $domainId): void
    {
        $this->domainId = $domainId;
        $this->domain();
    }

    public function measure(bool $refresh = false): void
    {
        $domain = $this->domain();
        abort_unless(DomainResource::canEdit($domain), 403);
        $audit = $domain->latestWebsiteAudit;
        if ($audit === null) {
            $this->addError('pagespeed', 'Run a website audit first.');

            return;
        }
        $count = app(PageSpeedDispatcher::class)->request($audit, $refresh);
        Notification::make()->title($count > 0 ? 'PageSpeed measurements queued' : 'Existing measurements reused')->success()->send();
    }

    public function render(): View
    {
        $domain = $this->domain();
        $audit = $domain->latestWebsiteAudit;
        $measurements = PageSpeedMeasurement::query()->with('page')->whereHas('page', fn ($q) => $q->where('website_audit_id', $audit?->id ?? 0))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('page_speed_measurements as newer')
                ->whereColumn('newer.website_audit_page_id', 'page_speed_measurements.website_audit_page_id')
                ->whereColumn('newer.strategy', 'page_speed_measurements.strategy')->whereColumn('newer.id', '>', 'page_speed_measurements.id'))
            ->orderBy('website_audit_page_id')->orderBy('strategy')->get();

        return view('livewire.domain-page-speed', ['audit' => $audit, 'measurements' => $measurements,
            'active' => $measurements->contains(fn ($row) => $row->active_key !== null),
            'canEdit' => DomainResource::canEdit($domain), 'enabled' => (bool) config('pagespeed.enabled')]);
    }

    private function domain(): Domain
    {
        abort_unless(Filament::auth()->check(), 403);
        $domain = Domain::query()->findOrFail($this->domainId);
        abort_unless(DomainResource::canView($domain), 403);

        return $domain;
    }
}
