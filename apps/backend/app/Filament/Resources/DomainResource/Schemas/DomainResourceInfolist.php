<?php

declare(strict_types=1);

namespace App\Filament\Resources\DomainResource\Schemas;

use App\Enums\PageType;
use App\Models\Domain;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DomainResourceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead Overview')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('domain'),
                        TextEntry::make('metadata.homepage_url')
                            ->label('Homepage URL')
                            ->state(fn (Domain $record): ?string => self::normalizeText(data_get($record->metadata, 'homepage_url')))
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true),
                        TextEntry::make('platform')->badge()->placeholder('unknown'),
                        TextEntry::make('confidence')->badge()->color(fn (?string $state): string => (float) $state >= 75 ? 'success' : ((float) $state >= 45 ? 'warning' : 'danger')),
                        TextEntry::make('country')->placeholder('—'),
                        TextEntry::make('niche')->placeholder('—'),
                        TextEntry::make('business_model')->placeholder('—'),
                        TextEntry::make('metadata.product_count_guess')
                            ->label('Product Count Guess')
                            ->state(fn (Domain $record): ?string => self::normalizeText(data_get($record->metadata, 'product_count_guess')))
                            ->placeholder('—'),
                        TextEntry::make('metadata.contact_url')
                            ->label('Contact URL')
                            ->state(fn (Domain $record): ?string => self::normalizeText(data_get($record->metadata, 'contact_url')))
                            ->url(fn (?string $state): ?string => $state, shouldOpenInNewTab: true)
                            ->placeholder('—'),
                    ]),
                    TextEntry::make('metadata.frontend_stack')
                        ->label('Frontend Stack')
                        ->state(fn (Domain $record): array => self::normalizeList(data_get($record->metadata, 'frontend_stack')))
                        ->badge()
                        ->separator(',')
                        ->listWithLineBreaks(),
                    TextEntry::make('metadata.matched_signals')
                        ->label('Matched Signals')
                        ->state(fn (Domain $record): array => self::normalizeList(data_get($record->metadata, 'matched_signals')))
                        ->badge()
                        ->separator(',')
                        ->listWithLineBreaks(),
                    TextEntry::make('last_crawled_at')->label('Last Crawled')->since(),
                    TextEntry::make('updated_at')->label('Last Check')->since(),
                ]),
            Section::make('Score Data')
                ->schema([
                    TextEntry::make('latestLeadScore.opportunity_score')->label('Opportunity Score')->badge(),
                    KeyValueEntry::make('latestLeadScore.score_breakdown')
                        ->label('Score Breakdown')
                        ->state(fn (Domain $record): array => self::normalizeKeyValue($record->latestLeadScore?->score_breakdown))
                        ->columnSpanFull(),
                    TextEntry::make('latestLeadScore.score_reasons')
                        ->label('Score Reasons')
                        ->state(fn (Domain $record): array => self::normalizeList($record->latestLeadScore?->score_reasons))
                        ->badge()
                        ->separator(',')
                        ->listWithLineBreaks()
                        ->columnSpanFull(),
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
            Section::make('Latest Fingerprint')
                ->schema([
                    TextEntry::make('latestFingerprint.platform')->label('Detected Platform')->badge()->placeholder('unknown'),
                    TextEntry::make('latestFingerprint.confidence')->label('Confidence')->badge()->placeholder('—'),
                    TextEntry::make('latestFingerprint.frontend_stack')
                        ->label('Frontend Stack')
                        ->state(fn (Domain $record): array => self::normalizeList($record->latestFingerprint?->frontend_stack))
                        ->badge()
                        ->separator(',')
                        ->listWithLineBreaks()
                        ->placeholder('—'),
                    TextEntry::make('latestFingerprint.detected_at')->label('Detected At')->since()->placeholder('—'),
                    TextEntry::make('latestFingerprint.signals')
                        ->label('Signals')
                        ->state(fn (Domain $record): array => self::normalizeList($record->latestFingerprint?->signals))
                        ->badge()
                        ->separator(',')
                        ->listWithLineBreaks()
                        ->placeholder('—')
                        ->columnSpanFull(),
                    KeyValueEntry::make('latestFingerprint.whatweb_payload')
                        ->label('WhatWeb Summary')
                        ->state(fn (Domain $record): array => self::normalizeKeyValue($record->latestFingerprint?->whatweb_payload))
                        ->columnSpanFull()
                        ->placeholder('Not available'),
                    KeyValueEntry::make('latestFingerprint.raw_payload')
                        ->label('Raw Fingerprint Payload')
                        ->state(fn (Domain $record): array => self::normalizeKeyValue($record->latestFingerprint?->raw_payload))
                        ->columnSpanFull()
                        ->placeholder('Not available'),
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
                            TextEntry::make('trigger_type')->label('Trigger Type')->badge(),
                            TextEntry::make('crawl_payload.job_type')->label('Worker Job Type')->badge()->placeholder('—'),
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

    private static function hasPageType(Domain $record, string $pageType): bool
    {
        return $record->pageClassifications->contains(
            fn ($classification) => $classification->page_type?->value === $pageType || $classification->page_type === $pageType,
        );
    }

    private static function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            $normalized = trim((string) $value);

            return $normalized === '' ? null : $normalized;
        }

        if (is_array($value)) {
            return self::normalizeText(reset($value));
        }

        return null;
    }

    private static function normalizeList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = is_array($value) ? $value : [$value];
        $normalized = [];

        array_walk_recursive($items, function (mixed $item) use (&$normalized): void {
            if (! is_scalar($item)) {
                return;
            }

            $string = trim((string) $item);

            if ($string === '') {
                return;
            }

            $normalized[] = $string;
        });

        return array_values(array_unique($normalized));
    }

    private static function normalizeKeyValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            if (! is_scalar($key)) {
                continue;
            }

            $normalized[(string) $key] = self::normalizeKeyValueItem($item);
        }

        return $normalized;
    }

    private static function normalizeKeyValueItem(mixed $item): ?string
    {
        if ($item === null) {
            return null;
        }

        if (is_scalar($item)) {
            return (string) $item;
        }

        if (is_array($item)) {
            return json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_object($item) && method_exists($item, '__toString')) {
            return (string) $item;
        }

        $encoded = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
