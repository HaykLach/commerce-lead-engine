<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Models\Domain;
use App\Models\DomainFingerprint;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FingerprintIngestionService
{
    public function store(array $payload): DomainFingerprint
    {
        $domainId = $payload['domain_id'] ?? $this->resolveDomainId($payload['domain'] ?? null);

        if ($domainId === null) {
            throw ValidationException::withMessages([
                'domain' => 'Unable to resolve domain. Provide a valid domain_id or known domain.',
            ]);
        }

        $attributes = [
            'domain_id' => $domainId,
            'platform' => $payload['platform'],
            'confidence' => $payload['confidence'] ?? 0,
            'frontend_stack' => $payload['frontend_stack'] ?? [],
            'signals' => $payload['signals'] ?? [],
            'raw_payload' => $payload['raw_payload'] ?? null,
            'whatweb_payload' => $payload['whatweb_payload'] ?? null,
            'detected_at' => $payload['detected_at'] ?? Carbon::now(),
        ];

        return DomainFingerprint::query()->create($attributes)->fresh();
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
