<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DomainResource\Pages;
use App\Filament\Resources\DomainResource\Schemas\DomainResourceForm;
use App\Filament\Resources\DomainResource\Schemas\DomainResourceInfolist;
use App\Filament\Resources\DomainResource\Tables\DomainsTable;
use App\Models\Domain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Leads / Domains';

    protected static ?string $modelLabel = 'Lead Domain';

    protected static ?string $pluralModelLabel = 'Lead Domains';

    public static function form(Schema $schema): Schema
    {
        return DomainResourceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DomainsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DomainResourceInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'latestLeadScore',
            'latestMetric',
            'latestFingerprint',
            'sources',
            'crawlJobs',
            'pageClassifications',
        ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDomains::route('/'),
            'view' => Pages\ViewDomain::route('/{record}'),
        ];
    }
}
