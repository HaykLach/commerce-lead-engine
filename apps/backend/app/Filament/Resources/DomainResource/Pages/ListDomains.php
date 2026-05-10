<?php

declare(strict_types=1);

namespace App\Filament\Resources\DomainResource\Pages;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Tab;

class ListDomains extends ListRecords
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getTabs(): array
    {
        return [
            null => Tab::make('All'),
            'new' => Tab::make('New')
                ->modifyQueryUsing(fn ($query) => $query->whereNull('visited_at'))
                ->badge(Domain::whereNull('visited_at')->count()),
            'visited' => Tab::make('Visited')
                ->modifyQueryUsing(fn ($query) => $query->whereNotNull('visited_at')),
        ];
    }

    public function markDomainVisited(string $domain): void
    {
        Domain::where('domain', $domain)->first()?->markAsVisited();

        Notification::make()
            ->title('Copied: ' . $domain)
            ->success()
            ->duration(2000)
            ->send();
    }
}
