<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Domain;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class B2bLeadsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $count = Domain::query()
            ->where('business_model', 'b2b')
            ->count();

        return [
            Stat::make('B2B Leads', number_format($count))
                ->description('Domains tagged as B2B')
                ->color('success'),
        ];
    }
}
