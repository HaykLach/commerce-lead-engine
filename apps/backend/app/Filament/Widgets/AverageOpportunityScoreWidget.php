<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\LeadScore;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AverageOpportunityScoreWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $average = (float) LeadScore::query()->avg('opportunity_score');

        return [
            Stat::make('Average Opportunity Score', number_format($average, 2)),
        ];
    }
}
