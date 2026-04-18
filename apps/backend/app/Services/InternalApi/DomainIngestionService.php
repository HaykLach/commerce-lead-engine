<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Models\Domain;
use Illuminate\Support\Carbon;

class DomainIngestionService
{
    public function upsert(array $payload): Domain
    {
        $normalizedDomain = $this->normalizeDomain($payload['normalized_domain'] ?? $payload['domain']);
        $timestamp = Carbon::now();
        $domain = Domain::query()->firstOrNew(['normalized_domain' => $normalizedDomain]);
        $isNew = ! $domain->exists;

        $attributes = [
            'domain' => $normalizedDomain,
            'normalized_domain' => $normalizedDomain,
            'confidence' => $payload['confidence'] ?? 0,
            'metadata' => $payload['metadata'] ?? null,
            'last_seen_at' => $payload['last_seen_at'] ?? $timestamp,
            'last_crawled_at' => $payload['last_crawled_at'] ?? null,
        ];

        $attributes['first_seen_at'] = $isNew
            ? ($payload['first_seen_at'] ?? $timestamp)
            : ($domain->first_seen_at ?? $payload['first_seen_at'] ?? $timestamp);

        foreach (['status', 'platform', 'country', 'niche', 'business_model'] as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = $payload[$field];
            }
        }

        $domain->fill($attributes);
        $domain->save();

        return $domain->fresh();
    }

    private function normalizeDomain(string $domain): string
    {
        $candidate = strtolower(trim($domain));
        $withoutScheme = preg_replace('/^https?:\/\//', '', $candidate) ?? $candidate;

        return rtrim($withoutScheme, '/');
    }
}
