<?php

declare(strict_types=1);

namespace App\Filament\Resources\DomainResource\Pages;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Services\Crawl\CrawlOrchestratorService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recrawl')
                ->label('Recrawl (placeholder)')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (Domain $record): void {
                    app(CrawlOrchestratorService::class)->dispatch($record->domain);

                    Notification::make()
                        ->title('Recrawl request submitted')
                        ->body('This is a placeholder action. Full queue orchestration remains in the crawl services.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
