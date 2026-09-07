<x-filament-panels::page>
    <x-outreach-styles />
    <div class="ffp-panel ffp-stack">
    <p>Review drafts one by one, save any edits, then approve and schedule. All dates and send times use Armenia time (Asia/Yerevan).</p>
    <label class="ffp-row ffp-heading">Report date <input class="ffp-date" type="date" wire:model.live="date"></label>
    @error('date') <p role="alert">{{ $message }}</p> @enderror
    <div class="ffp-stats">
        @foreach ([['Ready for review', $summary['drafts']['draft'] ?? 0, 'heroicon-o-pencil-square', '#6366f1'], ['Scheduled drafts', $summary['drafts']['scheduled'] ?? 0, 'heroicon-o-calendar-days', '#0284c7'], ['Unfinished jobs, including backlog', $summary['unfinished_audits'] + $summary['unfinished_drafts'], 'heroicon-o-clock', '#d97706']] as [$label, $count, $icon, $color])
            <div class="ffp-card ffp-pad"><div class="ffp-row ffp-between"><span class="ffp-muted">{{ $label }}</span><x-filament::icon :icon="$icon" class="ffp-icon" style="color:{{ $color }}" /></div><p class="ffp-number">{{ $count }}</p></div>
        @endforeach
    </div>
    @if (! $summary['drafting_enabled']) <p>Email drafting is not configured or enabled yet.</p> @endif
    @if (! config('outreach_delivery.enabled')) <p>Sending is paused. Approved messages will wait until sending is enabled.</p> @endif
    @if ($snapshot)
        <p>Daily summary recorded at {{ $snapshot->created_at->timezone('Asia/Yerevan')->format('H:i') }} · Telegram: {{ $snapshot->notification_status }}. Counts below reflect current progress.</p>
        @if ($snapshot->error) <p>{{ $snapshot->error }}</p> @endif
    @else <p>The daily Telegram summary is scheduled for 22:00 Armenia time.</p> @endif
    <div class="ffp-card ffp-table-wrap">
        <table class="ffp-table">
            <thead><tr><th>Domain</th><th>Recipient</th><th>Subject</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            @forelse ($drafts as $draft)
                @if (\App\Filament\Resources\DomainResource::canView($draft->domain))
                <tr wire:key="report-draft-{{ $draft->id }}" aria-selected="{{ $selectedDraftId === $draft->id ? 'true' : 'false' }}">
                    <td><strong>{{ $draft->domain->domain }}</strong><p class="ffp-muted">Draft #{{ $draft->id }}</p></td><td>{{ $draft->recipient_email }}</td>
                    <td class="ffp-subject">{{ $draft->subject ?? 'Not generated yet' }}</td><td><x-outreach-status :status="$draft->messages->sortByDesc('id')->first()?->status ?? $draft->status" /></td>
                    <td><x-filament::button size="sm" icon="heroicon-o-pencil-square" color="gray" wire:click="selectDraft({{ $draft->id }})">Review</x-filament::button></td>
                </tr>
                @endif
            @empty <tr><td class="ffp-empty" colspan="5">No drafts were created on this date.</td></tr> @endforelse
            </tbody>
        </table>
    </div>
    {{ $drafts->links() }}
    @if ($selected)
        <x-filament::section heading="Review email">
            <p><strong>{{ $selected->domain->domain }}</strong></p>
            <livewire:domain-outreach-drafts :domain-id="$selected->domain_id" :initial-draft-id="$selected->id" :key="'daily-review-'.$selected->id" />
        </x-filament::section>
    @endif
    <x-filament::section heading="Website analysis activity">
        <p>Latest 25 audits started on this date. Partial or failed audits may need attention even when a draft is available.</p>
        <div class="ffp-card ffp-table-wrap"><table class="ffp-table"><thead><tr><th>Domain</th><th>Audit</th><th>Status</th><th>Started (Armenia)</th></tr></thead><tbody>
        @forelse ($audits as $audit)
            @if (\App\Filament\Resources\DomainResource::canView($audit->domain))
                <tr><td><strong>{{ $audit->domain->domain }}</strong></td><td>#{{ $audit->id }}</td><td><x-outreach-status :status="$audit->status" /></td><td>{{ $audit->created_at->timezone('Asia/Yerevan')->format('H:i') }}</td></tr>
            @endif
        @empty <tr><td colspan="4" class="ffp-empty">No website audits started on this date.</td></tr> @endforelse
        </tbody></table></div>
    </x-filament::section>
    </div>
</x-filament-panels::page>
