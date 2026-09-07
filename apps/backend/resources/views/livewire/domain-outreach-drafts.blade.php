<div class="ffp-panel ffp-stack" @if($draft?->active_domain_id) wire:poll.10s @endif>
    <x-outreach-styles />
    <p>Email drafts are saved for review. Only approved, scheduled messages can be sent.</p>
    @if (! $configured) <p>Configure the OpenAI drafting key and model to enable generation. PageSpeed is optional.</p> @endif
    @if ($canEdit && $configured)
        <x-filament::button size="sm" wire:click="generate" wire:loading.attr="disabled">Generate draft</x-filament::button>
        @if ($draft && ! $draft->active_domain_id)
            <x-filament::button size="sm" color="gray" wire:click="generate(true)" wire:loading.attr="disabled">Generate a new version</x-filament::button>
        @endif
    @endif
    @error('draft') <p role="alert">{{ $message }}</p> @enderror
    @if ($history->isNotEmpty())
        <p>Recent drafts:</p>
        @foreach ($history as $item)
            <x-filament::button size="xs" color="gray" wire:click="loadDraft({{ $item->id }})">#{{ $item->id }} · {{ ucfirst($item->status) }}</x-filament::button>
        @endforeach
    @endif
    @if ($draft)
        <p style="margin-top: 1rem"><strong>Draft #{{ $draft->id }} · {{ ucfirst($draft->status) }}</strong></p>
        <p>Recipient: {{ $draft->recipient_email }} · Audit #{{ $draft->website_audit_id }}</p>
        @if (! $current) <p role="status">The selected contact or evidence has changed or expired. Generate a new draft before using this version.</p> @endif
        @if ($draft->error) <p role="status">{{ $draft->error }}</p> @endif
        @if ($draft->next_attempt_at?->isFuture()) <p>Next attempt: {{ $draft->next_attempt_at->toDateTimeString() }} UTC</p> @endif
        @if (in_array($draft->status, ['draft', 'scheduled'], true))
            @if ($canEdit && $current && $draft->status === 'draft')
                <div wire:key="email-editor-{{ $draft->id }}" style="margin: 1.25rem 0">
                    {{ $this->editor }}
                    @error('subject') <p role="alert">{{ $message }}</p> @enderror
                    @error('body') <p role="alert">{{ $message }}</p> @enderror
                    <div style="margin-top: 1rem">
                        <x-filament::button icon="heroicon-o-check" wire:click="saveDraft" wire:loading.attr="disabled">Save draft</x-filament::button>
                    </div>
                </div>
                <label>Send at (Armenia time) <input class="ffp-date" type="datetime-local" wire:model="scheduledFor"></label>
                @error('scheduledFor') <p role="alert">{{ $message }}</p> @enderror
                <x-filament::button size="sm" wire:click="approveAndSchedule" wire:loading.attr="disabled">Approve and schedule</x-filament::button>
            @endif
            @if (! $canEdit || ! $current || $draft->status !== 'draft')
                <p><strong>{{ $draft->subject }}</strong></p>
                <div class="ffp-prose">{!! \App\Services\Outreach\EmailMarkup::clean($draft->body_html ?? \App\Services\Outreach\EmailMarkup::fromText($draft->body ?? '')) !!}</div>
            @endif
            <p>Review the subject, wording, and evidence before using this draft. Saved edits remain drafts.</p>
        @endif
        @foreach ($messages as $delivery)
            <p>Message #{{ $delivery->id }} · {{ ucfirst($delivery->status) }} · Scheduled for {{ $delivery->scheduled_at->timezone('Asia/Yerevan')->format('Y-m-d H:i') }} Armenia time</p>
            @if ($delivery->error) <p>{{ $delivery->error }}</p> @endif
            @if ($delivery->status === 'accepted') <p>Accepted by SMTP at {{ $delivery->accepted_at->timezone('Asia/Yerevan')->format('Y-m-d H:i') }}. This does not confirm inbox delivery.</p> @endif
            @if ($canEdit && in_array($delivery->status, ['scheduled', 'blocked'], true))
                <x-filament::button size="sm" color="gray" wire:click="cancelMessage({{ $delivery->id }})">Cancel and return to draft</x-filament::button>
                @if ($delivery->status === 'scheduled')
                    <label>New send time (Armenia) <input class="ffp-date" type="datetime-local" wire:model="scheduledFor"></label>
                    @error('scheduledFor') <p role="alert">{{ $message }}</p> @enderror
                    <x-filament::button size="sm" wire:click="rescheduleMessage({{ $delivery->id }})">Reschedule</x-filament::button>
                @endif
            @endif
        @endforeach
        <details style="margin-top: 1rem">
            <summary>Source findings and generation details</summary>
            <p>Model: {{ $draft->response_model ?? $draft->model }} · Prompt: {{ $draft->prompt_version }} · Attempts: {{ $draft->attempts }}</p>
            @if ($draft->usage) <p>Input tokens: {{ $draft->usage['input_tokens'] ?? 'Unavailable' }} · Output tokens: {{ $draft->usage['output_tokens'] ?? 'Unavailable' }}</p> @endif
            @foreach ($draft->evidence['facts'] as $fact)
                <p>{{ in_array($fact['id'], $draft->selected_issue_ids ?? [], true) ? 'Used: ' : '' }}{{ $fact['text'] }}</p>
                <p>Source: {{ $fact['source_url'] }} · Observed: {{ $fact['observed_at'] }}</p>
            @endforeach
        </details>
    @else
        <p>No email drafts yet. Select a contact and complete a website audit first.</p>
    @endif
</div>
