<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Models\Domain;
use App\Models\Fingerprint;
use Illuminate\Support\Carbon;

class FingerprintIngestionService
{
    public function store(array $payload): Fingerprint
    {
        $domainId = $payload['domain_id'] ?? $this->resolveDomainId($payload['domain'] ?? null);

        $attributes = [
            'domain_id' => $domainId,
            'name' => $payload['name'] ?? sprintf('%s:%s', $payload['platform'], Carbon::now()->toIso8601String()),
            'platform' => $payload['platform'],
            'version' => $payload['version'] ?? null,
            'priority' => $payload['priority'] ?? 100,
            'confidence_weight' => $payload['confidence_weight'] ?? 1,
            'confidence' => $payload['confidence'] ?? null,
            'rules' => $payload['rules'] ?? [],
            'frontend_stack' => $payload['frontend_stack'] ?? [],
            'signals' => $payload['signals'] ?? [],
            'raw_payload' => $payload['raw_payload'] ?? null,
            'whatweb_payload' => $payload['whatweb_payload'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'detected_at' => $payload['detected_at'] ?? Carbon::now(),
            'is_active' => $payload['is_active'] ?? true,
        ];

        return Fingerprint::query()->create($attributes)->fresh();
    }

    private function resolveDomainId(?string $domain): ?int
    {
        if (empty($domain)) {
            return null;
        }

        $normalizedDomain = $this->normalizeDomain($domain);

        return Domain::query()
            ->where('normalized_domain', $normalizedDomain)
            ->value('id');
    }

    private function normalizeDomain(string $domain): string
    {
        $candidate = strtolower(trim($domain));
        $withoutScheme = preg_replace('/^https?:\/\//', '', $candidate) ?? $candidate;
        $withoutWww = str_starts_with($withoutScheme, 'www.') ? substr($withoutScheme, 4) : $withoutScheme;

        return rtrim($withoutWww, '/');
    }
}
