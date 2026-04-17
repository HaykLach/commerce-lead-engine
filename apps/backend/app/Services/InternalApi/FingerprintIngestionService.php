<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Models\Fingerprint;

class FingerprintIngestionService
{
    public function store(array $payload): Fingerprint
    {
        return Fingerprint::query()->create($payload);
    }
}
