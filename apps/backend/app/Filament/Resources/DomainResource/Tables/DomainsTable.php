<?php

declare(strict_types=1);

namespace App\Filament\Resources\DomainResource\Tables;

use App\Models\Domain;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable(['domain', 'normalized_domain'])
                    ->sortable(),
                TextColumn::make('metadata.store_name')
                    ->label('Store')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('platform')
                    ->placeholder('unknown')
                    ->badge()
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'warning',
                        'queued' => 'info',
                        'crawling' => 'primary',
                        'processed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('confidence')
                    ->badge()
                    ->color(fn (?string $state): string => (float) $state >= 75 ? 'success' : ((float) $state >= 45 ? 'warning' : 'danger'))
                    ->sortable(),
                TextColumn::make('latestLeadScore.opportunity_score')
                    ->label('Opportunity')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('country')->sortable()->toggleable(),
                TextColumn::make('niche')->sortable()->toggleable(),
                TextColumn::make('business_model')->label('Business Model')->sortable()->toggleable(),
                TextColumn::make('last_crawled_at')->since()->sortable()->toggleable(),
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
                        TextInput::make('confidence_min')->numeric()->minValue(0)->maxValue(100),
                        TextInput::make('confidence_max')->numeric()->minValue(0)->maxValue(100),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['confidence_min'] ?? null, fn (Builder $q, $value) => $q->where('confidence', '>=', (float) $value))
                            ->when($data['confidence_max'] ?? null, fn (Builder $q, $value) => $q->where('confidence', '<=', (float) $value));
                    }),
                Filter::make('opportunity_score_range')
                    ->form([
                        TextInput::make('opportunity_min')->numeric()->minValue(0)->maxValue(100),
                        TextInput::make('opportunity_max')->numeric()->minValue(0)->maxValue(100),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->whereHas('latestLeadScore', function (Builder $scoreQuery) use ($data): void {
                            $scoreQuery
                                ->when($data['opportunity_min'] ?? null, fn (Builder $q, $value) => $q->where('opportunity_score', '>=', (float) $value))
                                ->when($data['opportunity_max'] ?? null, fn (Builder $q, $value) => $q->where('opportunity_score', '<=', (float) $value));
                        });
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('last_seen_at', 'desc');
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
}
