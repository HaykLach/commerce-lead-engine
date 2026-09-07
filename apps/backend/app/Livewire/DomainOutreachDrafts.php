<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Models\OutreachMessage;
use App\Services\Outreach\DraftDispatcher;
use App\Services\Outreach\DraftException;
use App\Services\Outreach\DraftRunner;
use App\Services\Outreach\EmailMarkup;
use App\Services\Outreach\MessageApproval;
use Filament\Facades\Filament;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DomainOutreachDrafts extends Component implements HasSchemas
{
    use InteractsWithSchemas;

    public function editor(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('subject')->required()->maxLength(160),
            RichEditor::make('body')->label('Email')->required()
                ->toolbarButtons([['bold', 'italic', 'underline', 'strike'], ['bulletList', 'orderedList', 'blockquote'], ['undo', 'redo']]),
        ]);
    }

    #[Locked]
    public int $domainId;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public int $revision = 0;

    public string $subject = '';

    public string|array $body = '';

    public string $scheduledFor = '';

    public function mount(int $domainId, ?int $initialDraftId = null): void
    {
        $this->domainId = $domainId;
        $this->scheduledFor = now('Asia/Yerevan')->addDay()->setTime(10, 0)->format('Y-m-d\TH:i');
        if ($initialDraftId !== null) {
            $this->loadDraft($initialDraftId);

            return;
        }
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
        $this->body = $draft->body_html ?? EmailMarkup::fromText($draft->body ?? '');
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
        $this->body = $this->editor->getState()['body'];
        $this->validate(['subject' => ['required', 'string', 'max:160', 'not_regex:/[\r\n<>]/'], 'body' => ['required', 'string', 'max:20000']]);
        DB::transaction(function () use ($domain): void {
            $domain = Domain::query()->lockForUpdate()->findOrFail($domain->id);
            $draft = $domain->outreachDrafts()->lockForUpdate()->findOrFail($this->draftId);
            if ($draft->status !== 'draft' || $draft->revision !== $this->revision || ! app(DraftRunner::class)->current($draft)) {
                $this->addError('draft', 'This draft or its source changed. Reload it or generate a new draft.');

                return;
            }
            $html = EmailMarkup::clean($this->body);
            $plain = EmailMarkup::text($html);
            $words = EmailMarkup::words($plain);
            if (mb_strlen($plain) > 5000 || ! str_contains($words, EmailMarkup::words($draft->prompt['cta'])) || ! str_ends_with($words, EmailMarkup::words($draft->prompt['signature']))) {
                $this->addError('body', 'Keep the agreed call invitation and signature unchanged.');

                return;
            }
            $draft->update(['subject' => trim($this->subject), 'body' => $plain, 'body_html' => $html, 'edited_at' => now(), 'revision' => $draft->revision + 1]);
            $this->revision = $draft->revision;
            $this->body = $html;
            Notification::make()->title('Draft saved for review')->success()->send();
        });
    }

    public function approveAndSchedule(): void
    {
        $this->body = $this->editor->getState()['body'];
        $this->domain();
        $draft = $this->domain()->outreachDrafts()->findOrFail($this->draftId);
        if ($this->subject !== $draft->subject || EmailMarkup::clean($this->body) !== EmailMarkup::clean($draft->body_html ?? EmailMarkup::fromText($draft->body ?? ''))) {
            $this->addError('draft', 'Save your edits before approving.');

            return;
        }
        app(MessageApproval::class)->approve($this->domainId, $draft->id, $this->revision, $this->scheduledFor);
        $this->loadDraft($draft->id);
        Notification::make()->title('Approved and scheduled')->success()->send();
    }

    public function cancelMessage(int $id): void
    {
        app(MessageApproval::class)->cancel($this->domainId, $id);
        $this->loadDraft($this->draftId);
    }

    public function rescheduleMessage(int $id): void
    {
        app(MessageApproval::class)->reschedule($this->domainId, $id, $this->scheduledFor);
    }

    public function render(): View
    {
        $domain = $this->domain();
        $draft = $domain->outreachDrafts()->find($this->draftId);

        return view('livewire.domain-outreach-drafts', ['messages' => OutreachMessage::where('domain_id', $domain->id)->where('outreach_draft_id', $this->draftId)->latest('id')->get(), 'draft' => $draft, 'history' => $domain->outreachDrafts()->latest('id')->limit(10)->get(),
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
