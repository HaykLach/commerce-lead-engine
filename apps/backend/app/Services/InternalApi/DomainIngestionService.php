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

        $attributes = [
            'domain' => strtolower(trim($payload['domain'])),
            'normalized_domain' => $normalizedDomain,
            'confidence' => $payload['confidence'] ?? 0,
            'metadata' => $payload['metadata'] ?? null,
            'first_seen_at' => $payload['first_seen_at'] ?? $timestamp,
            'last_seen_at' => $payload['last_seen_at'] ?? $timestamp,
            'last_crawled_at' => $payload['last_crawled_at'] ?? null,
        ];

        foreach (['status', 'platform', 'country', 'niche', 'business_model'] as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = $payload[$field];
            }
        }

        $domain = Domain::query()->updateOrCreate(
            ['normalized_domain' => $normalizedDomain],
            $attributes,
        );

        return $domain->fresh();
    }

    private function normalizeDomain(string $domain): string
    {
        $candidate = strtolower(trim($domain));
        $withoutScheme = preg_replace('/^https?:\/\//', '', $candidate) ?? $candidate;

        return rtrim($withoutScheme, '/');
    }
}
