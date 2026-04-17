<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CrawlJobStatus;
use App\Enums\CrawlTriggerType;
use App\Models\CrawlJob;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawlJob>
 */
class CrawlJobFactory extends Factory
{
    protected $model = CrawlJob::class;

    public function definition(): array
    {
        return [
            'domain_id' => Domain::factory(),
            'status' => CrawlJobStatus::Queued,
            'trigger_type' => CrawlTriggerType::Discovery,
            'priority' => $this->faker->numberBetween(1, 10),
            'attempt' => 1,
            'max_attempts' => 3,
            'scheduled_at' => now(),
            'next_crawl_at' => now()->addDay(),
            'crawl_payload' => [
                'user_agent' => 'crawler-bot',
                'max_pages' => 100,
            ],
            'crawl_summary' => [
                'queued_urls' => 0,
            ],
        ];
    }
}
