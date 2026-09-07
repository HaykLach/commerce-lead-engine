<div class="ffp-panel ffp-stack" @if($active || $audit?->active_domain_id) wire:poll.15s @endif>
    <x-outreach-styles />
    <div class="ffp-row ffp-between">
        <div><h3 class="ffp-heading">PageSpeed performance</h3><p class="ffp-muted">Lighthouse lab results for your homepage and category page</p></div>
        <div class="ffp-row">
            @if ($enabled && $canEdit)
                <x-filament::button icon="heroicon-o-bolt" size="sm" wire:click="measure" wire:loading.attr="disabled" :disabled="! $audit || ! in_array($audit->status, ['completed', 'partial'], true) || $active">Measure performance</x-filament::button>
                @if ($measurements->isNotEmpty() && ! $active)<x-filament::button icon="heroicon-o-arrow-path" size="sm" color="gray" wire:click="measure(true)" wire:loading.attr="disabled">Refresh measurements</x-filament::button>@endif
            @endif
        </div>
    </div>
    @if (! $enabled)<p class="ffp-notice">PageSpeed collection is disabled in the server configuration.</p>@endif
    @error('pagespeed')<p role="alert" class="ffp-notice">{{ $message }}</p>@enderror
    @if (! $audit)<p class="ffp-empty">Run a website audit first.</p>
    @elseif ($measurements->isEmpty())<p class="ffp-empty">No PageSpeed measurements for audit #{{ $audit->id }} yet.</p>@endif
    @foreach ($measurements as $measurement)
        @php
            $lab = $measurement->result['lab'] ?? [];
            $rating = $lab['rating'] ?? 'unavailable';
            $color = match ($rating) { 'good' => '#0cce6b', 'needs_improvement' => '#ffa400', 'poor' => '#ff4e42', default => '#94a3b8' };
            $score = $measurement->performance_score;
        @endphp
        <article class="ffp-card" wire:key="pagespeed-{{ $measurement->id }}">
            <header class="ffp-pad ffp-row ffp-between">
                <div>
                    <div class="ffp-row"><x-filament::icon :icon="$measurement->strategy === 'mobile' ? 'heroicon-o-device-phone-mobile' : 'heroicon-o-computer-desktop'" class="ffp-icon"/><h4 class="ffp-heading">{{ ucfirst($measurement->page->kind) }} · {{ ucfirst($measurement->strategy) }}</h4></div>
                    <p class="ffp-url">{{ $measurement->final_url ?? $measurement->requested_url }}</p>
                </div>
                <div class="ffp-row"><x-outreach-status :status="$measurement->status" />@if ($measurement->expires_at?->isPast())<x-filament::badge color="warning">Expired</x-filament::badge>@endif</div>
            </header>
            @if ($measurement->error)<p class="ffp-notice" role="status">{{ $measurement->error }}</p>@endif
            @if ($measurement->next_attempt_at?->isFuture())<p class="ffp-pad ffp-muted">Next eligible attempt: {{ $measurement->next_attempt_at->toDateTimeString() }} UTC</p>@endif
            @if ($measurement->status === 'completed')
                <div class="ffp-performance">
                    <div class="ffp-score">
                        <div class="ffp-gauge" role="img" aria-label="Lighthouse performance score: {{ $score ?? 'Unavailable' }} out of 100">
                            <svg viewBox="0 0 120 120" aria-hidden="true"><circle cx="60" cy="60" r="52" fill="none" stroke="{{ $color }}" stroke-opacity=".13" stroke-width="8"/><circle cx="60" cy="60" r="52" fill="none" stroke="{{ $color }}" stroke-width="8" stroke-linecap="round" pathLength="100" stroke-dasharray="{{ max(0, min(100, $score ?? 0)) }} 100"/></svg>
                            <span class="ffp-gauge-value" style="color:{{ $color }}">{{ $score ?? '—' }}</span>
                        </div>
                        <strong>Performance</strong><p class="ffp-muted">{{ ucfirst(str_replace('_', ' ', $rating)) }}</p>
                        <div class="ffp-legend"><span><i class="ffp-dot" style="background:#ff4e42"></i>0–{{ (int) config('pagespeed.poor_score_below') - 1 }}</span><span><i class="ffp-dot" style="background:#ffa400"></i>{{ config('pagespeed.poor_score_below') }}–{{ (int) config('pagespeed.good_score_from') - 1 }}</span><span><i class="ffp-dot" style="background:#0cce6b"></i>{{ config('pagespeed.good_score_from') }}–100</span></div>
                    </div>
                    <div><h5 class="ffp-heading">Metrics</h5><dl class="ffp-metrics">
                        @foreach ($lab['metrics'] ?? [] as $name => $metric)
                            <div class="ffp-metric"><dt class="ffp-muted">{{ ucwords(str_replace('-', ' ', $name)) }}</dt><dd>
                                @if ($metric['value'] === null) —
                                @elseif ($metric['unit'] === 'ms' && $name !== 'total-blocking-time') {{ number_format($metric['value'] / 1000, 1) }} <small>s</small>
                                @elseif ($metric['unit'] === 'ms') {{ number_format($metric['value']) }} <small>ms</small>
                                @else {{ number_format($metric['value'], 3) }} @endif
                            </dd></div>
                        @endforeach
                    </dl></div>
                </div>
                <div class="ffp-section ffp-muted">Measured {{ $measurement->measured_at?->format('Y-m-d H:i') }} UTC · Lighthouse {{ $measurement->lighthouse_version ?? 'version unavailable' }} · {{ $measurement->attempts }} attempt(s). Lab results vary and do not establish real-user Core Web Vitals or lost sales.</div>
                @foreach ($lab['warnings'] ?? [] as $warning)<p class="ffp-notice">Lab warning: {{ $warning }}</p>@endforeach
                <details class="ffp-section" open><summary>Lab findings · {{ count($lab['diagnostics'] ?? []) }}</summary><div class="ffp-findings">
                    @forelse ($lab['diagnostics'] ?? [] as $finding)
                        <div class="ffp-finding ffp-row ffp-between"><span>{{ $finding['title'] }}</span><strong>{{ $finding['display_value'] ?? '' }}</strong></div>
                    @empty <p class="ffp-muted">No additional findings were stored for this measurement.</p>@endforelse
                </div></details>
                <details class="ffp-section"><summary>Real-user data · Chrome UX Report</summary><div class="ffp-findings">
                    @foreach ($measurement->result['field'] ?? [] as $source => $field)
                        <div class="ffp-finding"><strong>{{ ucfirst($source) }} response</strong> · {{ ucfirst($field['status']) }}
                            @if ($field['status'] === 'available')<p class="ffp-url">Scope: {{ str_replace('_', ' ', $field['scope']) }} · {{ $field['id'] }}</p>@endif
                            @foreach ($field['metrics'] ?? [] as $name => $metric)<p class="ffp-muted">{{ $name }}: {{ $metric['percentile'] }} {{ $metric['unit'] }} (75th percentile) · {{ $metric['category'] ?? 'Unclassified' }}</p>@endforeach
                        </div>
                    @endforeach
                </div></details>
            @endif
        </article>
    @endforeach
</div>
