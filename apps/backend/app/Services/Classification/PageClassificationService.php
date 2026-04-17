<?php

declare(strict_types=1);

namespace App\Services\Classification;

class PageClassificationService
{
    public function classify(string $url, string $html): array
    {
        // Placeholder: rule-based / heuristic classification implementation.
        return [
            'page_type' => 'unknown',
            'signals' => [],
        ];
    }
}
