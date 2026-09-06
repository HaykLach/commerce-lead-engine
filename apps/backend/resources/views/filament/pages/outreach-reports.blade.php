<x-filament-panels::page>
    <p>Review drafts one by one, save any edits, then approve and schedule. All dates and send times use Armenia time (Asia/Yerevan).</p>
    <label>Report date <input type="date" wire:model.live="date"></label>
    @error('date') <p role="alert">{{ $message }}</p> @enderror
    <p>Ready for review: {{ $summary['drafts']['draft'] ?? 0 }} · Scheduled drafts: {{ $summary['drafts']['scheduled'] ?? 0 }} · Unfinished jobs, including backlog: {{ $summary['unfinished_audits'] + $summary['unfinished_drafts'] }}</p>
    @if (! $summary['drafting_enabled']) <p>Email drafting is not configured or enabled yet.</p> @endif
    @if (! config('outreach_delivery.enabled')) <p>Sending is paused. Approved messages will wait until sending is enabled.</p> @endif
    @if ($snapshot)
        <p>Daily summary recorded at {{ $snapshot->created_at->timezone('Asia/Yerevan')->format('H:i') }} · Telegram: {{ $snapshot->notification_status }}. Counts below reflect current progress.</p>
        @if ($snapshot->error) <p>{{ $snapshot->error }}</p> @endif
    @else <p>The daily Telegram summary is scheduled for 22:00 Armenia time.</p> @endif
    <div style="overflow-x: auto">
        <table style="width:100%; text-align:left">
            <thead><tr><th>Domain</th><th>Recipient</th><th>Subject</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($drafts as $draft)
                @if (\App\Filament\Resources\DomainResource::canView($draft->domain))
                <tr wire:key="report-draft-{{ $draft->id }}">
                    <td>{{ $draft->domain->domain }}</td><td>{{ $draft->recipient_email }}</td>
                    <td>{{ $draft->subject ?? 'Not generated yet' }}</td><td>{{ ucfirst($draft->messages->sortByDesc('id')->first()?->status ?? $draft->status) }}</td>
                    <td><x-filament::button size="sm" wire:click="selectDraft({{ $draft->id }})">Review</x-filament::button></td>
                </tr>
                @endif
            @empty <tr><td colspan="5">No drafts were created on this date.</td></tr> @endforelse
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
        @forelse ($audits as $audit)
            @if (\App\Filament\Resources\DomainResource::canView($audit->domain))
                <p>{{ $audit->domain->domain }} · {{ ucfirst($audit->status) }} · Audit #{{ $audit->id }}</p>
            @endif
        @empty <p>No website audits started on this date.</p> @endforelse
    </x-filament::section>
</x-filament-panels::page>
