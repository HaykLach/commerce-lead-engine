<div @if($active || $audit?->active_domain_id) wire:poll.15s @endif>
    <p>Internal performance measurements for the homepage and selected category page.</p>
    @if (! $enabled)
        <p>PageSpeed collection is disabled in the server configuration.</p>
    @elseif ($canEdit)
        <x-filament::button size="sm" wire:click="measure" wire:loading.attr="disabled" :disabled="! $audit || ! in_array($audit->status, ['completed', 'partial'], true) || $active">Measure performance</x-filament::button>
        @if ($measurements->isNotEmpty() && ! $active)
            <x-filament::button size="sm" color="gray" wire:click="measure(true)" wire:loading.attr="disabled">Refresh measurements</x-filament::button>
        @endif
    @endif
    @error('pagespeed') <p role="alert">{{ $message }}</p> @enderror
    @if (! $audit)
        <p>Run a website audit first.</p>
    @elseif ($measurements->isEmpty())
        <p>No PageSpeed measurements for audit #{{ $audit->id }} yet.</p>
    @endif
    @foreach ($measurements as $measurement)
        <div wire:key="pagespeed-{{ $measurement->id }}" style="margin-top: 1rem; padding-top: .75rem; border-top: 1px solid #8884">
            <strong>{{ ucfirst($measurement->page->kind) }} · {{ ucfirst($measurement->strategy) }}</strong>
            <p>{{ $measurement->final_url ?? $measurement->requested_url }}</p>
            <p>{{ ucfirst($measurement->status) }} · {{ $measurement->attempts }} attempt(s)
                @if ($measurement->expires_at?->isPast()) · Expired @endif
            </p>
            @if ($measurement->next_attempt_at?->isFuture()) <p>Next eligible attempt: {{ $measurement->next_attempt_at->toDateTimeString() }} UTC</p> @endif
            @if ($measurement->error) <p role="status">{{ $measurement->error }}</p> @endif
            @if ($measurement->status === 'completed')
                <p>Lighthouse lab score: <strong>{{ $measurement->performance_score }}/100</strong>
                    · {{ str_replace('_', ' ', ucfirst($measurement->result['lab']['rating'])) }}
                    · {{ $measurement->measured_at->toDateTimeString() }} UTC
                    · Lighthouse {{ $measurement->lighthouse_version ?? 'version unavailable' }}
                </p>
                <dl>
                    @foreach ($measurement->result['lab']['metrics'] as $name => $metric)
                        <dt>{{ ucwords(str_replace('-', ' ', $name)) }}</dt>
                        <dd>{{ $metric['value'] === null ? 'Unavailable' : number_format($metric['value'], $metric['unit'] === 'ms' ? 0 : 3).' '.$metric['unit'] }}</dd>
                    @endforeach
                </dl>
                @if ($measurement->result['lab']['diagnostics'])
                    <p><strong>Lab findings</strong></p>
                    <ul>
                        @foreach ($measurement->result['lab']['diagnostics'] as $finding)
                            <li>{{ $finding['title'] }} @if ($finding['display_value']) — {{ $finding['display_value'] }} @endif</li>
                        @endforeach
                    </ul>
                @endif
                @foreach ($measurement->result['lab']['warnings'] as $warning) <p>Lab warning: {{ $warning }}</p> @endforeach
                @foreach ($measurement->result['field'] as $source => $field)
                    <p><strong>Real-user data ({{ $source }} response)</strong>: {{ ucfirst($field['status']) }}
                        @if ($field['status'] === 'available') · Scope: {{ str_replace('_', ' ', $field['scope']) }} · {{ $field['id'] }} @endif
                    </p>
                    @foreach ($field['metrics'] as $name => $metric)
                        <p>{{ $name }}: {{ $metric['percentile'] }} {{ $metric['unit'] }} (75th percentile) · {{ $metric['category'] ?? 'Unclassified' }}</p>
                    @endforeach
                @endforeach
                <p>Lab results vary. They do not establish real-user Core Web Vitals, a ranking penalty, or lost sales.</p>
            @endif
        </div>
    @endforeach
</div>
