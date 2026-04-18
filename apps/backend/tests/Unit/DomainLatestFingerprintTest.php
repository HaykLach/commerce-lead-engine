<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Domain;
use App\Models\Fingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainLatestFingerprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_fingerprint_relation_returns_latest_detected_at(): void
    {
        $domain = Domain::factory()->create();

        Fingerprint::query()->create([
            'domain_id' => $domain->id,
            'name' => 'fp-old',
            'platform' => 'woocommerce',
            'rules' => [],
            'detected_at' => now()->subHour(),
        ]);

        Fingerprint::query()->create([
            'domain_id' => $domain->id,
            'name' => 'fp-new',
            'platform' => 'shopify',
            'rules' => [],
            'detected_at' => now(),
        ]);

        $latest = $domain->fresh()->latestFingerprint;

        $this->assertNotNull($latest);
        $this->assertSame('shopify', $latest->platform);
        $this->assertSame('fp-new', $latest->name);
    }
}
