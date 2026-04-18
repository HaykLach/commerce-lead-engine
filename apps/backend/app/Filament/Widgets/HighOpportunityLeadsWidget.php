<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\LeadScore;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class HighOpportunityLeadsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $highOpportunityCount = LeadScore::query()
            ->where('opportunity_score', '>=', 70)
            ->count();

        return [
            Stat::make('High Opportunity Leads', number_format($highOpportunityCount))
                ->description('Opportunity score ≥ 70')
                ->color('warning'),
        ];
    }
}
