<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Models\Domain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class DomainIngestionService
{
    public function upsert(array $payload): Domain
    {
        $normalizedDomain = $this->normalizeDomain((string) ($payload['normalized_domain'] ?? $payload['domain'] ?? ''));
        $timestamp = Carbon::now();

        $domain = Domain::query()->firstOrNew(['normalized_domain' => $normalizedDomain]);
        $isNew = ! $domain->exists;

        $metadata = $domain->metadata ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $incomingMetadata = $payload['metadata'] ?? [];
        if (is_array($incomingMetadata)) {
            $metadata = array_replace_recursive($metadata, $incomingMetadata);
        }

        foreach (['source_url', 'source_type', 'source_context'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null) {
                $metadata[$key] = $payload[$key];
            }
        }

        $attributes = [
            'domain' => $normalizedDomain,
            'normalized_domain' => $normalizedDomain,
            'status' => array_key_exists('status', $payload) ? $payload['status'] : ($domain->status ?: 'pending'),
            'confidence' => array_key_exists('confidence', $payload) ? $payload['confidence'] : ($domain->confidence ?? 0),
            'metadata' => $metadata,
            'last_seen_at' => $payload['last_seen_at'] ?? $timestamp,
            'last_crawled_at' => $payload['last_crawled_at'] ?? $domain->last_crawled_at,
        ];

        $attributes['first_seen_at'] = $isNew
            ? ($payload['first_seen_at'] ?? $timestamp)
            : ($domain->first_seen_at ?? $payload['first_seen_at'] ?? $timestamp);

        foreach (['platform', 'country', 'niche', 'business_model'] as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = $payload[$field];
            }
        }

        Log::info('Domain ingestion upsert starting', [
            'incoming_domain' => $payload['domain'] ?? null,
            'normalized_domain' => $normalizedDomain,
            'exists_before_save' => ! $isNew,
            'metadata_keys' => array_keys($metadata),
        ]);

        $domain->fill($attributes);
        $domain->save();

        Log::info('Domain ingestion upsert completed', [
            'domain_id' => $domain->id,
            'normalized_domain' => $domain->normalized_domain,
            'status' => is_object($domain->status) ? $domain->status->value : $domain->status,
            'metadata_keys' => array_keys((array) $domain->metadata),
        ]);

        return $domain->fresh();
    }

    private function normalizeDomain(string $domain): string
    {
        $candidate = strtolower(trim($domain));
        $candidate = preg_replace('/^https?:\/\//', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/^www\./', '', $candidate) ?? $candidate;

        $parts = explode('/', $candidate);

        return rtrim($parts[0], '.');
    }
}
