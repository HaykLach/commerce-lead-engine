<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Models\Domain;
use App\Models\Fingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FingerprintControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_fingerprint_for_domain(): void
    {
        $domain = Domain::factory()->create([
            'domain' => 'example.com',
            'normalized_domain' => 'example.com',
        ]);

        $this->postJson('/api/v1/internal/fingerprints', [
            'domain' => 'https://www.example.com',
            'platform' => 'shopify',
            'confidence' => 88.4,
            'frontend_stack' => ['react', 'tailwind'],
            'signals' => ['cdn.shopify.com', 'shopify.theme'],
            'raw_payload' => ['platform' => 'shopify', 'confidence' => 88.4],
            'whatweb_payload' => ['plugins' => [['name' => 'Country', 'version' => ['DE']]]],
            'detected_at' => '2026-04-18T12:30:00Z',
        ])->assertCreated()
            ->assertJsonPath('data.domain_id', $domain->id)
            ->assertJsonPath('data.platform', 'shopify');

        $fingerprint = Fingerprint::query()->firstOrFail();
        $this->assertSame($domain->id, $fingerprint->domain_id);
        $this->assertSame(['react', 'tailwind'], $fingerprint->frontend_stack);
        $this->assertSame(['cdn.shopify.com', 'shopify.theme'], $fingerprint->signals);
    }
}
