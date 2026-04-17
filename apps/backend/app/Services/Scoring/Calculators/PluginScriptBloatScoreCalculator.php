<?php

declare(strict_types=1);

namespace App\Services\Scoring\Calculators;

use App\DTOs\Scoring\ScoreComponentResultData;

class PluginScriptBloatScoreCalculator implements ScoreCalculatorInterface
{
    use NormalizesScores;

    public function key(): string
    {
        return 'plugin_script_bloat_score';
    }

    public function calculate(array $signals): ScoreComponentResultData
    {
        $scriptCount = (int) ($signals['script_count'] ?? 0);
        $thirdPartyScripts = (int) ($signals['third_party_script_count'] ?? 0);
        $totalJsKb = (float) ($signals['total_js_kb'] ?? 0);

        $bloatIndex = (40 * $this->ratio($scriptCount, 40))
            + (35 * $this->ratio($thirdPartyScripts, 20))
            + (25 * $this->ratio($totalJsKb, 1500));

        $score = $this->clamp($bloatIndex);

        $reasons = [sprintf('Script bloat index is %d/100 from script and payload volume.', $score)];

        if ($thirdPartyScripts >= 10) {
            $reasons[] = 'Heavy third-party script usage suggests plugin sprawl.';
        }

        if ($totalJsKb >= 900) {
            $reasons[] = 'JavaScript payload exceeds 900KB, indicating frontend performance drag.';
        }

        return new ScoreComponentResultData(
            score: $score,
            reasons: $reasons,
            rawMeasurements: [
                'script_count' => $scriptCount,
                'third_party_script_count' => $thirdPartyScripts,
                'total_js_kb' => $totalJsKb,
                'bloat_index' => $score,
            ],
        );
    }
}
