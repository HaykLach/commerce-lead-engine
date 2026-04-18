<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Models\Domain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainIngestionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_updates_snapshot_fields_by_normalized_domain(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'Example.com',
            'normalized_domain' => 'example.com',
            'platform' => null,
            'confidence' => 0,
            'metadata' => null,
        ]);

        $response = $this->postJson('/api/v1/internal/domains/upsert', [
            'domain' => 'https://www.example.com',
            'normalized_domain' => 'example.com',
            'platform' => 'shopify',
            'confidence' => 92.5,
            'country' => 'DE',
            'niche' => 'fashion',
            'last_crawled_at' => '2026-04-18T12:00:00Z',
            'metadata' => [
                'final_url' => 'https://www.example.com/',
                'status_code' => 200,
                'whatweb_country_hint' => 'DE',
            ],
        ])->assertOk();

        $domain->refresh();

        $this->assertSame($domain->id, $response->json('data.id'));
        $this->assertSame('shopify', $domain->platform);
        $this->assertSame('92.50', (string) $domain->confidence);
        $this->assertSame('DE', $domain->country);
        $this->assertSame('fashion', $domain->niche);
        $this->assertSame(200, $domain->metadata['status_code']);
        $this->assertNotNull($domain->last_crawled_at);
    }

    public function test_upsert_preserves_first_seen_at_for_existing_domain(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
            'first_seen_at' => '2026-04-10T00:00:00Z',
        ]);

        $this->postJson('/api/v1/internal/domains/upsert', [
            'domain' => 'https://example.com',
            'normalized_domain' => 'example.com',
            'first_seen_at' => '2026-04-18T00:00:00Z',
            'last_seen_at' => '2026-04-18T00:00:00Z',
        ])->assertOk();

        $domain->refresh();

        $this->assertSame('2026-04-10 00:00:00', $domain->first_seen_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-18 00:00:00', $domain->last_seen_at?->format('Y-m-d H:i:s'));
    }
}
