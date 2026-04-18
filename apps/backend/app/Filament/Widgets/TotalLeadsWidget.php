<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Domain;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TotalLeadsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $totalLeads = Domain::query()->count();

        return [
            Stat::make('Total Leads', number_format($totalLeads))
                ->description('All discovered lead domains')
                ->color('primary'),
        ];
    }
}
