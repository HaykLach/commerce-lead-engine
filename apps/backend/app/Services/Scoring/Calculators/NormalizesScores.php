<?php

declare(strict_types=1);

namespace App\Services\Scoring\Calculators;

trait NormalizesScores
{
    private function clamp(float|int $value, int $min = 0, int $max = 100): int
    {
        return (int) max($min, min($max, round($value)));
    }

    private function ratio(float|int $value, float|int $max): float
    {
        if ($max <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, ((float) $value) / ((float) $max)));
    }
}
