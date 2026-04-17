<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Models\PageClassification;

class PageClassificationIngestionService
{
    public function store(array $payload): PageClassification
    {
        return PageClassification::query()->create($payload);
    }
}
