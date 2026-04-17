<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\LeadGrade;
use App\Models\CrawlJob;
use App\Models\Domain;
use App\Models\LeadScore;
use App\Models\ScoreConfig;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadScore>
 */
class LeadScoreFactory extends Factory
{
    protected $model = LeadScore::class;

    public function definition(): array
    {
        $score = $this->faker->randomFloat(2, 0, 100);

        return [
            'domain_id' => Domain::factory(),
            'crawl_job_id' => CrawlJob::factory(),
            'score_config_id' => ScoreConfig::factory(),
            'opportunity_score' => $score,
            'grade' => match (true) {
                $score >= 80 => LeadGrade::Hot,
                $score >= 50 => LeadGrade::Warm,
                default => LeadGrade::Cold,
            },
            'score_breakdown' => [
                'platform_confidence' => 20,
                'checkout_detection' => 15,
            ],
            'score_reasons' => [
                [
                    'code' => 'checkout_detected',
                    'label' => 'Checkout flow detected',
                    'weight' => 15,
                    'impact' => 'positive',
                ],
            ],
            'version' => 'v1',
            'computed_at' => now(),
        ];
    }
}
