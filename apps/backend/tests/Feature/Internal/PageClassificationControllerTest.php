<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Models\Domain;
use App\Models\PageClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageClassificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_accepts_domain_level_page_classification_payload(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
        ]);

        $payload = [
            'domain_id' => $domain->id,
            'product_page_found' => true,
            'category_page_found' => true,
            'cart_page_found' => true,
            'checkout_page_found' => false,
            'sample_product_url' => 'https://example.com/products/running-shoe',
            'sample_category_url' => 'https://example.com/collections/shoes',
            'sample_cart_url' => 'https://example.com/cart',
            'sample_checkout_url' => null,
            'product_count_guess' => 532,
            'product_count_bucket' => '201-1000',
            'classification_metadata' => [
                'sampled_urls' => ['https://example.com/', 'https://example.com/collections/shoes'],
            ],
            'classified_at' => '2026-04-18T00:00:00Z',
        ];

        $response = $this->postJson('/api/v1/internal/page-classifications', $payload)
            ->assertCreated()
            ->assertJsonPath('data.domain_id', $domain->id)
            ->assertJsonPath('data.product_page_found', true)
            ->assertJsonPath('data.product_count_guess', 532)
            ->assertJsonPath('data.product_count_bucket', '201-1000');

        $record = PageClassification::query()->findOrFail((int) $response->json('data.id'));

        $this->assertTrue($record->product_page_found);
        $this->assertTrue($record->category_page_found);
        $this->assertSame('https://example.com/products/running-shoe', $record->sample_product_url);
        $this->assertSame(532, $record->product_count_guess);
        $this->assertSame('201-1000', $record->product_count_bucket);
        $this->assertSame(['sampled_urls' => ['https://example.com/', 'https://example.com/collections/shoes']], $record->classification_metadata);
    }

    public function test_store_resolves_domain_id_from_domain_when_not_supplied(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
        ]);

        $this->postJson('/api/v1/internal/page-classifications', [
            'domain' => 'example.com',
            'product_page_found' => false,
            'category_page_found' => true,
            'product_count_bucket' => '1-50',
        ])->assertCreated()
            ->assertJsonPath('data.domain_id', $domain->id)
            ->assertJsonPath('data.category_page_found', true);
    }
}
