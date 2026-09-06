<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Services\Outreach\DraftDispatcher;
use App\Services\Outreach\DraftException;
use App\Services\Outreach\DraftRunner;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DomainOutreachDrafts extends Component
{
    #[Locked]
    public int $domainId;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public int $revision = 0;

    public string $subject = '';

    public string $body = '';

    public function mount(int $domainId): void
    {
        $this->domainId = $domainId;
        $latest = $this->domain()->outreachDrafts()->latest('id')->first();
        if ($latest !== null) {
            $this->loadDraft($latest->id);
        }
    }

    public function loadDraft(int $id): void
    {
        $draft = $this->domain()->outreachDrafts()->findOrFail($id);
        $this->draftId = $draft->id;
        $this->revision = $draft->revision;
        $this->subject = $draft->subject ?? '';
        $this->body = $draft->body ?? '';
        $this->resetErrorBag();
    }

    public function generate(bool $regenerate = false): void
    {
        $domain = $this->domain();
        abort_unless(DomainResource::canEdit($domain), 403);
        try {
            $draft = app(DraftDispatcher::class)->request($domain, $regenerate);
            $this->loadDraft($draft->id);
            Notification::make()->title($draft->wasRecentlyCreated ? 'Draft record created' : 'Existing draft reused')->success()->send();
        } catch (DraftException $exception) {
            $this->addError('draft', $exception->getMessage());
        }
    }

    public function saveDraft(): void
    {
        $domain = $this->domain();
        abort_unless(DomainResource::canEdit($domain), 403);
        $this->validate(['subject' => ['required', 'string', 'max:160', 'not_regex:/[\r\n<>]/'], 'body' => ['required', 'string', 'max:5000', 'not_regex:/[<>\x00]/']]);
        DB::transaction(function () use ($domain): void {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $draft = $domain->outreachDrafts()->lockForUpdate()->findOrFail($this->draftId);
            if ($draft->status !== 'draft' || $draft->revision !== $this->revision || ! app(DraftRunner::class)->current($draft)) {
                $this->addError('draft', 'This draft or its source changed. Reload it or generate a new draft.');

                return;
            }
            if (! str_contains($this->body, $draft->prompt['cta']) || ! str_ends_with(trim($this->body), $draft->prompt['signature'])) {
                $this->addError('body', 'Keep the agreed call invitation and signature unchanged.');

                return;
            }
            $draft->update(['subject' => trim($this->subject), 'body' => trim($this->body), 'edited_at' => now(), 'revision' => $draft->revision + 1]);
            $this->revision = $draft->revision;
            Notification::make()->title('Draft saved for review')->success()->send();
        });
    }

    public function render(): View
    {
        $domain = $this->domain();
        $draft = $domain->outreachDrafts()->find($this->draftId);

        return view('livewire.domain-outreach-drafts', ['draft' => $draft, 'history' => $domain->outreachDrafts()->latest('id')->limit(10)->get(),
            'current' => $draft !== null && app(DraftRunner::class)->current($draft), 'canEdit' => DomainResource::canEdit($domain),
            'configured' => config('outreach.enabled') && filled(config('outreach.api_key')) && filled(config('outreach.model'))]);
    }

    private function domain(): Domain
    {
        abort_unless(Filament::auth()->check(), 403);
        $domain = Domain::query()->findOrFail($this->domainId);
        abort_unless(DomainResource::canView($domain), 403);

        return $domain;
    }
}
