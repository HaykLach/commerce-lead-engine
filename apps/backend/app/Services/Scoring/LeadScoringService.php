<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\DTOs\LeadScoreResultData;
use App\Services\Scoring\Calculators\CustomStackComplexityScoreCalculator;
use App\Services\Scoring\Calculators\EcommerceMaturityScoreCalculator;
use App\Services\Scoring\Calculators\ErpAutomationFitScoreCalculator;
use App\Services\Scoring\Calculators\PlatformFitScoreCalculator;
use App\Services\Scoring\Calculators\PluginScriptBloatScoreCalculator;
use App\Services\Scoring\Calculators\ScoreCalculatorInterface;
use App\Services\Scoring\Calculators\SeoGapScoreCalculator;

class LeadScoringService
{
    /**
     * @var array<int, ScoreCalculatorInterface>
     */
    private array $calculators;

    /**
     * @param array<string, int>|null $configuredWeights
     * @param array<int, ScoreCalculatorInterface>|null $calculators
     */
    public function __construct(
        private readonly ?array $configuredWeights = null,
        ?array $calculators = null,
    ) {
        $this->calculators = $calculators ?? [
            new SeoGapScoreCalculator(),
            new PluginScriptBloatScoreCalculator(),
            new CustomStackComplexityScoreCalculator(),
            new EcommerceMaturityScoreCalculator(),
            new ErpAutomationFitScoreCalculator(),
            new PlatformFitScoreCalculator(),
        ];
    }

    public function score(string $domain, array $signals = []): LeadScoreResultData
    {
        $weights = $this->resolveWeights();
        $componentScores = [];
        $componentReasons = [];
        $rawMeasurements = [];
        $aggregatedReasons = [];

        foreach ($this->calculators as $calculator) {
            $component = $calculator->calculate($signals);
            $componentScores[$calculator->key()] = $component->score;
            $componentReasons[$calculator->key()] = $component->reasons;
            $rawMeasurements[$calculator->key()] = $component->rawMeasurements;

            foreach ($component->reasons as $reason) {
                $aggregatedReasons[] = sprintf('[%s] %s', $calculator->key(), $reason);
            }
        }

        $opportunityScore = $this->calculateWeightedScore($componentScores, $weights);

        return new LeadScoreResultData(
            domain: $domain,
            total: $opportunityScore,
            breakdown: [
                'opportunity_score' => $opportunityScore,
                'weights' => $weights,
                'component_scores' => $componentScores,
                'component_reasons' => $componentReasons,
                'raw_measurements' => $rawMeasurements,
                'reasons' => $aggregatedReasons,
            ],
        );
    }

    /**
     * @param array<string, int> $componentScores
     * @param array<string, int> $weights
     */
    private function calculateWeightedScore(array $componentScores, array $weights): int
    {
        $totalWeight = 0;
        $weightedTotal = 0.0;

        foreach ($componentScores as $key => $score) {
            $weight = (int) ($weights[$key] ?? 0);
            $totalWeight += $weight;
            $weightedTotal += $score * $weight;
        }

        if ($totalWeight === 0) {
            return 0;
        }

        return (int) max(0, min(100, round($weightedTotal / $totalWeight)));
    }

    /**
     * @return array<string, int>
     */
    private function resolveWeights(): array
    {
        if (is_array($this->configuredWeights)) {
            return $this->configuredWeights;
        }

        if (function_exists('config')) {
            /** @var array<string, int> $weights */
            $weights = (array) config('scoring.weights', []);

            return $weights;
        }

        return [];
    }
}
