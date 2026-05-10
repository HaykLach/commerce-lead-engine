<?php

declare(strict_types=1);

namespace App\Filament\Resources\DomainResource\Tables;

use App\Filament\Resources\DomainResource;
use App\Models\Domain;
use App\Enums\DomainStatus;
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
                    ->sortable()
                    ->html()
                    ->formatStateUsing(fn (string $state): string => self::domainCell($state)),
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
                    ->color(fn (?DomainStatus $state): string => match ($state) {
                        DomainStatus::Pending => 'warning',
                        DomainStatus::Queued => 'info',
                        DomainStatus::Crawling => 'primary',
                        DomainStatus::Processed => 'success',
                        DomainStatus::Failed => 'danger',
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
                        $min = $data['opportunity_min'] ?? null;
                        $max = $data['opportunity_max'] ?? null;

                        if ($min === null && $max === null) {
                            return $query;
                        }

                        return $query->whereHas('latestLeadScore', function (Builder $scoreQuery) use ($min, $max): void {
                            $scoreQuery
                                ->when($min !== null, fn (Builder $q) => $q->where('opportunity_score', '>=', (float) $min))
                                ->when($max !== null, fn (Builder $q) => $q->where('opportunity_score', '<=', (float) $max));
                        });
                    }),
            ])
            ->recordClasses(fn (Domain $record): ?string => filled($record->visited_at) ? 'domain-visited' : null)
            ->recordUrl(fn (Domain $record): string => DomainResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([])
            ->defaultSort('last_seen_at', 'desc');
    }

    private static function domainCell(string $domain): string
    {
        // data-domain carries the value safely HTML-escaped; JS reads it via
        // $el.dataset.domain so no domain value is ever embedded in the JS expression.
        // htmlspecialchars(ENT_COMPAT) on the JS encodes & → &amp; etc. so the
        // double-quoted HTML attribute stays valid; the browser decodes it before
        // Alpine evaluates the expression.
        $domainDisplay = e($domain);
        $domainAttr    = htmlspecialchars($domain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $js = '(function(d){'
            .     'function fb(t){'
            .         'var a=document.createElement(\'textarea\');'
            .         'a.value=t;'
            .         'a.style.cssText=\'position:fixed;left:-9999px;top:-9999px\';'
            .         'document.body.appendChild(a);'
            .         'a.select();'
            .         'document.execCommand(\'copy\');'
            .         'a.remove()'
            .     '}'
            .     'if(navigator.clipboard&&window.isSecureContext){'
            .         'navigator.clipboard.writeText(d).catch(function(){fb(d)})'
            .     '}else{fb(d)}'
            . '})($el.dataset.domain);'
            . '$wire.call(\'markDomainVisited\',$el.dataset.domain)';

        $jsAttr = htmlspecialchars($js, ENT_COMPAT, 'UTF-8');

        return '<div class="flex items-center gap-2">'
            . '<button type="button"'
            .     ' x-data'
            .     ' x-on:click.stop="' . $jsAttr . '"'
            .     ' data-domain="' . $domainAttr . '"'
            .     ' class="text-gray-400 hover:text-primary-500 dark:hover:text-primary-400 transition-colors shrink-0"'
            .     ' title="Copy domain">'
            .     '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"'
            .         ' stroke-width="1.5" stroke="currentColor" class="w-4 h-4">'
            .         '<path stroke-linecap="round" stroke-linejoin="round"'
            .             ' d="M15.666 3.888A2.25 2.25 0 0 0 13.5 2.25h-3c-1.03 0-1.9.693-2.166'
            .             ' 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 0 1-.75.75H9a.75.75'
            .             ' 0 0 1-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11'
            .             ' 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 0 1-2.25'
            .             ' 2.25H6.75A2.25 2.25 0 0 1 4.5 19.5V6.257c0-1.108.806-2.057'
            .             ' 1.907-2.185a48.208 48.208 0 0 1 1.927-.184" />'
            .     '</svg>'
            . '</button>'
            . '<span>' . $domainDisplay . '</span>'
            . '</div>';
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
