<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DomainStatus;
use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        $host = $this->faker->domainName();

        return [
            'domain' => $host,
            'normalized_domain' => strtolower($host),
            'status' => DomainStatus::Pending,
            'platform' => $this->faker->randomElement(['shopify', 'woocommerce', 'magento', null]),
            'confidence' => $this->faker->randomFloat(2, 0, 100),
            'country' => $this->faker->countryCode(),
            'niche' => $this->faker->randomElement(['fashion', 'beauty', 'electronics', 'home']),
            'business_model' => $this->faker->randomElement(['b2c', 'b2b', 'd2c']),
            'first_seen_at' => now()->subDays($this->faker->numberBetween(1, 30)),
            'last_seen_at' => now(),
            'last_crawled_at' => now()->subHours($this->faker->numberBetween(1, 72)),
            'metadata' => [
                'language' => 'en',
                'seed' => 'factory',
            ],
        ];
    }
}
