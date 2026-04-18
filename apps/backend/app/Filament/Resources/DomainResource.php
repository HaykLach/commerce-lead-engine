<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\PageType;
use App\Filament\Resources\DomainResource\Pages;
use App\Models\Domain;
use BackedEnum;
use Filament\Forms\Form;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainResource extends Resource
{
    protected static ?string $model = Domain::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Leads / Domains';

    protected static ?string $modelLabel = 'Lead Domain';

    protected static ?string $pluralModelLabel = 'Lead Domains';

    public static function form(Filament\Schemas\Schema $schema): Filament\Schemas\Schema
    {
        return $schema;
    }


    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'latestLeadScore',
            'latestMetric',
            'sources',
            'crawlJobs',
            'pageClassifications',
        ]);
    }
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable(['domain', 'normalized_domain'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('metadata.store_name')
                    ->label('Store')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\BadgeColumn::make('platform')
                    ->placeholder('unknown')
                    ->colors([
                        'success' => fn (?string $state): bool => filled($state),
                    ])
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'info' => 'queued',
                        'primary' => 'crawling',
                        'success' => 'processed',
                        'danger' => 'failed',
                    ])
                    ->sortable(),
                Tables\Columns\TextColumn::make('confidence')
                    ->badge()
                    ->color(fn (?string $state): string => (float) $state >= 75 ? 'success' : ((float) $state >= 45 ? 'warning' : 'danger'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('latestLeadScore.opportunity_score')
                    ->label('Opportunity')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                Tables\Columns\TextColumn::make('country')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('niche')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('business_model')->label('Business Model')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('last_crawled_at')->since()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->options(fn (): array => self::optionsFor('platform')),
                SelectFilter::make('niche')
                    ->options(fn (): array => self::optionsFor('niche')),
                SelectFilter::make('country')
                    ->options(fn (): array => self::optionsFor('country')),
                SelectFilter::make('business_model')
                    ->options(fn (): array => self::optionsFor('business_model')),
                Filter::make('confidence_range')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('confidence_min')->numeric()->minValue(0)->maxValue(100),
                        \Filament\Forms\Components\TextInput::make('confidence_max')->numeric()->minValue(0)->maxValue(100),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['confidence_min'] ?? null, fn (Builder $q, $value) => $q->where('confidence', '>=', (float) $value))
                            ->when($data['confidence_max'] ?? null, fn (Builder $q, $value) => $q->where('confidence', '<=', (float) $value));
                    }),
                Filter::make('opportunity_score_range')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('opportunity_min')->numeric()->minValue(0)->maxValue(100),
                        \Filament\Forms\Components\TextInput::make('opportunity_max')->numeric()->minValue(0)->maxValue(100),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->whereHas('latestLeadScore', function (Builder $scoreQuery) use ($data): void {
                            $scoreQuery
                                ->when($data['opportunity_min'] ?? null, fn (Builder $q, $value) => $q->where('opportunity_score', '>=', (float) $value))
                                ->when($data['opportunity_max'] ?? null, fn (Builder $q, $value) => $q->where('opportunity_score', '<=', (float) $value));
                        });
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('last_seen_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Lead Overview')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('domain'),
                        TextEntry::make('metadata.homepage_url')->label('Homepage URL')->url(fn (?string $state) => $state, shouldOpenInNewTab: true),
                        TextEntry::make('platform')->badge()->placeholder('unknown'),
                        TextEntry::make('confidence')->badge()->color(fn (?string $state): string => (float) $state >= 75 ? 'success' : ((float) $state >= 45 ? 'warning' : 'danger')),
                        TextEntry::make('country')->placeholder('—'),
                        TextEntry::make('niche')->placeholder('—'),
                        TextEntry::make('business_model')->placeholder('—'),
                        TextEntry::make('metadata.product_count_guess')->label('Product Count Guess')->placeholder('—'),
                        TextEntry::make('metadata.contact_url')->label('Contact URL')->url(fn (?string $state) => $state, shouldOpenInNewTab: true)->placeholder('—'),
                    ]),
                    TextEntry::make('metadata.frontend_stack')->label('Frontend Stack')->badge()->separator(',')->listWithLineBreaks(),
                    TextEntry::make('metadata.matched_signals')->label('Matched Signals')->badge()->separator(',')->listWithLineBreaks(),
                    TextEntry::make('last_crawled_at')->label('Last Crawled')->since(),
                    TextEntry::make('updated_at')->label('Last Check')->since(),
                ]),
            Section::make('Score Data')
                ->schema([
                    TextEntry::make('latestLeadScore.opportunity_score')->label('Opportunity Score')->badge(),
                    KeyValueEntry::make('latestLeadScore.score_breakdown')->label('Score Breakdown')->columnSpanFull(),
                    TextEntry::make('latestLeadScore.score_reasons')->label('Score Reasons')->badge()->separator(',')->listWithLineBreaks()->columnSpanFull(),
                ])
                ->columns(1),
            Section::make('Page Classification')
                ->schema([
                    IconEntry::make('has_homepage')->label('Homepage')->boolean()->state(fn (Domain $record): bool => self::hasPageType($record, PageType::Home->value)),
                    IconEntry::make('has_product_page')->label('Product Page Found')->boolean()->state(fn (Domain $record): bool => self::hasPageType($record, PageType::Product->value)),
                    IconEntry::make('has_category_page')->label('Category Page Found')->boolean()->state(fn (Domain $record): bool => self::hasPageType($record, PageType::Collection->value)),
                    IconEntry::make('has_cart_page')->label('Cart Page Found')->boolean()->state(fn (Domain $record): bool => self::hasPageType($record, PageType::Cart->value)),
                    IconEntry::make('has_checkout_page')->label('Checkout Page Found')->boolean()->state(fn (Domain $record): bool => self::hasPageType($record, PageType::Checkout->value)),
                    IconEntry::make('has_contact_page')->label('Contact Page Found')->boolean()->state(fn (Domain $record): bool => self::hasPageType($record, PageType::Contact->value)),
                ])
                ->columns(3),
            Section::make('Fingerprint Data')
                ->schema([
                    TextEntry::make('latestMetric.platform')->label('Detected Platform')->badge()->placeholder('unknown'),
                    TextEntry::make('latestMetric.confidence')->label('Confidence')->badge(),
                    TextEntry::make('latestMetric.signal_summary.matched_signals')->label('Matched Signals')->badge()->separator(',')->listWithLineBreaks(),
                    KeyValueEntry::make('latestMetric.raw_signals')->label('Raw Signal Summary')->columnSpanFull(),
                    TextEntry::make('metadata.whatweb_summary')->label('WhatWeb Summary')->columnSpanFull()->placeholder('Not available'),
                ])
                ->columns(2),
            Section::make('Source History')
                ->schema([
                    RepeatableEntry::make('sources')
                        ->schema([
                            TextEntry::make('source_type')->badge(),
                            TextEntry::make('source_name'),
                            TextEntry::make('source_reference')->placeholder('—'),
                            TextEntry::make('discovered_at')->since(),
                        ])
                        ->contained(false)
                        ->columnSpanFull(),
                ]),
            Section::make('Crawl Jobs')
                ->schema([
                    RepeatableEntry::make('crawlJobs')
                        ->schema([
                            TextEntry::make('trigger_type')->label('Job Type')->badge(),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('scheduled_at')->dateTime()->placeholder('—'),
                            TextEntry::make('started_at')->dateTime()->placeholder('—'),
                            TextEntry::make('finished_at')->dateTime()->placeholder('—'),
                            TextEntry::make('failure_reason')->label('Errors')->placeholder('—')->columnSpanFull(),
                            TextEntry::make('id')->label('Recrawl')->state('Recrawl action placeholder')->badge(),
                        ])
                        ->contained(false)
                        ->columnSpanFull(),
                ]),
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

    private static function optionsFor(string $column): array
    {
        return Domain::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column, $column)
            ->toArray();
    }

    private static function hasPageType(Domain $record, string $pageType): bool
    {
        return $record->pageClassifications->contains(fn ($classification) => $classification->page_type?->value === $pageType || $classification->page_type === $pageType);
    }
}
