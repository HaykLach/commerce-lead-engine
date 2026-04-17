<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LeadScoreResultData
{
    public function __construct(
        public string $domain,
        public int $total,
        public array $breakdown = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'domain' => $this->domain,
            'total' => $this->total,
            'breakdown' => $this->breakdown,
        ];
    }
}
