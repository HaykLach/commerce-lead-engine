<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DomainDiscoveryRequestData
{
    public function __construct(
        public string $domain,
        public ?string $source = null,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            domain: (string) ($payload['domain'] ?? ''),
            source: $payload['source'] ?? null,
        );
    }
}
