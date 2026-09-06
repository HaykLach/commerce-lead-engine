<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Services\Contacts\ContactPurposeClassifier;
use App\Services\Contacts\ContactScanReader;
use App\Services\Contacts\ContactSelectionService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class DomainContacts extends Component
{
    use WithPagination;

    #[Locked]
    public int $domainId;

    #[Locked]
    public ?int $sourceContactId = null;

    public string $newEmail = '';

    public string $filter = 'all';

    public function mount(int $domainId): void
    {
        $this->domainId = $domainId;
        $this->domain();
    }

    public function selectContact(?int $contactId): void
    {
        app(ContactSelectionService::class)->select($this->domain(edit: true), $contactId);
        $this->saved('Primary contact saved');
    }

    public function updatePurpose(int $contactId, string $purpose): void
    {
        app(ContactSelectionService::class)->updatePurpose($this->domain(edit: true), $contactId, $purpose);
        $this->saved('Contact purpose saved');
    }

    public function excludeContact(int $contactId): void
    {
        app(ContactSelectionService::class)->exclude($this->domain(edit: true), $contactId, true);
        $this->saved('Contact excluded');
    }

    public function restoreContact(int $contactId): void
    {
        app(ContactSelectionService::class)->exclude($this->domain(edit: true), $contactId, false);
        $this->saved('Contact restored for review');
    }

    public function addEmail(): void
    {
        $domain = $this->domain(edit: true);
        $this->validate(['newEmail' => ['required', 'string', 'max:254', 'email:rfc']]);
        app(ContactSelectionService::class)->addManual($domain, $this->newEmail);
        $this->newEmail = '';
        $this->filter = 'all';
        $this->resetPage();
        $this->saved('Email saved. Select it below if it is the primary contact.');
    }

    public function showSources(int $contactId): void
    {
        $this->domain()->contacts()->findOrFail($contactId);
        $this->sourceContactId = $contactId;
        $this->dispatch('open-modal', id: 'contact-sources-'.$this->domainId);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $domain = $this->domain()->load(['primaryContact', 'latestPageClassification']);
        $contacts = $domain->contacts()->withCount('sources')
            ->when($this->filter === 'needs_review', fn ($query) => $query->where('review_status', 'candidate'))
            ->when($this->filter === 'excluded', fn ($query) => $query->where('review_status', 'excluded'))
            ->orderByRaw('id = ? desc', [$domain->primary_contact_id ?? 0])->orderBy('email')->paginate(25);

        return view('livewire.domain-contacts', [
            'domain' => $domain,
            'contacts' => $contacts,
            'contactCount' => $domain->contacts()->count(),
            'canEdit' => DomainResource::canEdit($domain),
            'purposes' => app(ContactPurposeClassifier::class),
            'selection' => app(ContactSelectionService::class),
            'scan' => app(ContactScanReader::class)->read($domain->latestPageClassification),
            'latestJob' => $domain->crawlJobs()->where('crawl_payload->job_type', 'page_classification')->latest('id')->first(),
            'sourceContact' => $this->sourceContactId === null ? null : $domain->contacts()->with('sources')->find($this->sourceContactId),
        ]);
    }

    private function domain(bool $edit = false): Domain
    {
        abort_unless(Filament::auth()->check(), 403);
        $domain = Domain::query()->findOrFail($this->domainId);
        abort_unless(DomainResource::canView($domain) && (! $edit || DomainResource::canEdit($domain)), 403);

        return $domain;
    }

    private function saved(string $message): void
    {
        $this->resetErrorBag();
        Notification::make()->title($message)->success()->send();
    }
}
