@props(['status'])
@php
    [$color, $icon, $label] = match ($status) {
        'draft' => ['info', 'heroicon-o-pencil-square', 'Ready for review'],
        'completed' => ['success', 'heroicon-o-check-circle', 'Completed'],
        'accepted' => ['success', 'heroicon-o-paper-airplane', 'SMTP accepted'],
        'sent' => ['success', 'heroicon-o-check-circle', 'Sent'],
        'scheduled' => ['info', 'heroicon-o-calendar-days', 'Scheduled'],
        'queued', 'pending' => ['gray', 'heroicon-o-clock', ucfirst($status)],
        'running', 'generating', 'sending' => ['info', 'heroicon-o-arrow-path', ucfirst($status)],
        'partial', 'unknown' => ['warning', 'heroicon-o-exclamation-triangle', ucfirst($status)],
        'failed', 'blocked' => ['danger', 'heroicon-o-exclamation-circle', ucfirst($status)],
        default => ['gray', 'heroicon-o-minus-circle', ucfirst($status)],
    };
@endphp
<x-filament::badge :color="$color" :icon="$icon">{{ $label }}</x-filament::badge>
