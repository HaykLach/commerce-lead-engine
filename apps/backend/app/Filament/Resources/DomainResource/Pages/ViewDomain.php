<?php

declare(strict_types=1);

namespace App\Filament\Resources\DomainResource\Pages;

use App\Filament\Resources\DomainResource;
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
                ->label('Recrawl')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(CrawlOrchestratorService::class)->dispatch($this->getRecord()->domain);

                    Notification::make()
                        ->title('Recrawl request submitted')
                        ->body('Queue orchestration remains handled by the crawl services layer.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
