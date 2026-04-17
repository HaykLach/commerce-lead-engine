<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Resources\DomainResource;
use App\Filament\Widgets\AverageOpportunityScoreWidget;
use App\Filament\Widgets\B2bLeadsWidget;
use App\Filament\Widgets\HighOpportunityLeadsWidget;
use App\Filament\Widgets\LeadsByPlatformWidget;
use App\Filament\Widgets\TotalLeadsWidget;
use App\Http\Middleware\EnsureInternalAdminAccess;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class InternalAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('internal-admin')
            ->path('internal/admin')
            ->brandName('Lead Engine Admin')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->resources([DomainResource::class])
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                TotalLeadsWidget::class,
                LeadsByPlatformWidget::class,
                HighOpportunityLeadsWidget::class,
                AverageOpportunityScoreWidget::class,
                B2bLeadsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                EnsureInternalAdminAccess::class,
            ]);
    }
}
