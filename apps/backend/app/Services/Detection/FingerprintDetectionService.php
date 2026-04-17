<?php

declare(strict_types=1);

namespace App\Services\Detection;

class FingerprintDetectionService
{
    public function detectPlatform(string $url, array $signals = []): array
    {
        // Placeholder: custom fingerprint matching implementation belongs here.
        return [
            'platform' => null,
            'confidence' => 0,
            'matched_rules' => [],
        ];
    }
}
