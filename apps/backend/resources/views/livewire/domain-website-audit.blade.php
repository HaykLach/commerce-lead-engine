<div @if($audit?->active_domain_id) wire:poll.10s @endif>
    @if ($canEdit)
        <x-filament::button size="sm" wire:click="analyze" wire:loading.attr="disabled" :disabled="$audit?->active_domain_id !== null">Analyze website</x-filament::button>
        @if ($audit && $audit->active_domain_id === null)
            <x-filament::button size="sm" color="gray" wire:click="analyze(true)" wire:loading.attr="disabled">Run a fresh audit</x-filament::button>
        @endif
    @endif
    @error('audit') <p role="alert">{{ $message }}</p> @enderror
    @if (! $audit)
        <p>No website audit yet. An audit checks the homepage and selected shop and contact pages.</p>
    @else
        <p style="margin: .75rem 0">
            <strong>Audit #{{ $audit->id }}</strong> · {{ ucfirst($audit->status) }}
            @if ($audit->finished_at) · Finished {{ $audit->finished_at->toDateTimeString() }} UTC @endif
        </p>
        @if ($audit->error) <p role="status">{{ $audit->error }}</p> @endif
        @if ($audit->summary)
            <p>{{ $audit->summary['pages_completed'] }} of {{ $audit->summary['pages_planned'] }} selected pages analyzed.</p>
        @endif
        <div style="overflow-x: auto; margin-top: 1rem">
            <table style="width: 100%; text-align: left; border-collapse: collapse">
                <thead><tr><th>Page</th><th>Status</th><th>Findings</th></tr></thead>
                <tbody>
                @foreach ($audit->pages as $page)
                    <tr wire:key="audit-page-{{ $page->id }}">
                        <td style="padding: .75rem; vertical-align: top; border-bottom: 1px solid #8884">
                            <strong>{{ ucfirst($page->kind) }}</strong><br>
                            <x-filament::link :href="$page->final_url ?? $page->url" target="_blank" rel="noopener noreferrer">{{ $page->final_url ?? $page->url }}</x-filament::link>
                        </td>
                        <td style="padding: .75rem; vertical-align: top; border-bottom: 1px solid #8884">
                            {{ ucfirst($page->status) }} · {{ $page->attempts }} attempt(s)
                            @if ($page->http_status)<br>HTTP {{ $page->http_status }}@endif
                            @if ($page->error)<p>{{ $page->error }}</p>@endif
                        </td>
                        <td style="padding: .75rem; vertical-align: top; border-bottom: 1px solid #8884">
                            @if ($page->evidence)
                                <p>Title: {{ $page->evidence['metadata']['title'] ?: 'No title found' }}</p>
                                <p>Description: {{ $page->evidence['metadata']['description'] ?: 'No meta description found' }}</p>
                                <p>H1 headings: {{ count($page->evidence['metadata']['h1']) }}</p>
                                <p>Images without an alt attribute: {{ $page->evidence['metadata']['images_without_alt_attribute'] }} / {{ $page->evidence['metadata']['images_total'] }}</p>
                                <p>Email candidates: {{ count($page->evidence['contacts']['emails']) }}</p>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
