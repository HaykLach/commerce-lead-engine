<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Domain;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LeadsByPlatformWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $platformRows = Domain::query()
            ->selectRaw('platform, COUNT(*) as total')
            ->whereNotNull('platform')
            ->groupBy('platform')
            ->orderByDesc('total')
            ->limit(4)
            ->get();

        if ($platformRows->isEmpty()) {
            return [
                Stat::make('Leads by Platform', 'No platform data yet'),
            ];
        }

        return $platformRows
            ->map(fn (Domain $row) => Stat::make((string) $row->platform, number_format((int) $row->total)))
            ->all();
    }
}
