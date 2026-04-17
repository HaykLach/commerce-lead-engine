<?php

declare(strict_types=1);

namespace App\DTOs\Scoring;

final readonly class ScoreComponentResultData
{
    /**
     * @param array<int, string> $reasons
     * @param array<string, mixed> $rawMeasurements
     */
    public function __construct(
        public int $score,
        public array $reasons,
        public array $rawMeasurements,
    ) {
    }

    /**
     * @return array{score:int,reasons:array<int,string>,raw_measurements:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'reasons' => $this->reasons,
            'raw_measurements' => $this->rawMeasurements,
        ];
    }
}
