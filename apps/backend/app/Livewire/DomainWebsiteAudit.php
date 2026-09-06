<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Services\Enrichment\WebsiteAuditDispatcher;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DomainWebsiteAudit extends Component
{
    #[Locked]
    public int $domainId;

    public function mount(int $domainId): void
    {
        $this->domainId = $domainId;
        $this->domain();
    }

    public function analyze(bool $refresh = false): void
    {
        $domain = $this->domain();
        abort_unless(DomainResource::canEdit($domain), 403);
        $audit = app(WebsiteAuditDispatcher::class)->request($domain, $refresh);
        Notification::make()->title($audit->wasRecentlyCreated ? 'Website audit queued' : 'Existing website audit reused')->success()->send();
    }

    public function render(): View
    {
        $domain = $this->domain();

        return view('livewire.domain-website-audit', [
            'audit' => $domain->websiteAudits()->with('pages')->latest('id')->first(),
            'canEdit' => DomainResource::canEdit($domain),
        ]);
    }

    private function domain(): Domain
    {
        abort_unless(Filament::auth()->check(), 403);
        $domain = Domain::query()->findOrFail($this->domainId);
        abort_unless(DomainResource::canView($domain), 403);

        return $domain;
    }
}
