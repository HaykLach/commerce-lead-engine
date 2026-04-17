<?php

declare(strict_types=1);

namespace App\Services\Scoring\Calculators;

use App\DTOs\Scoring\ScoreComponentResultData;

interface ScoreCalculatorInterface
{
    public function key(): string;

    /**
     * @param array<string, mixed> $signals
     */
    public function calculate(array $signals): ScoreComponentResultData;
}
