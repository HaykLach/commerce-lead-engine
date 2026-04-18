<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Domain;
use App\Models\DomainFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainLatestFingerprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_fingerprint_relation_returns_latest_detected_at(): void
    {
        $domain = Domain::factory()->create();

        DomainFingerprint::query()->create([
            'domain_id' => $domain->id,
            'platform' => 'woocommerce',
            'confidence' => 51.2,
            'detected_at' => now()->subHour(),
        ]);

        DomainFingerprint::query()->create([
            'domain_id' => $domain->id,
            'platform' => 'shopify',
            'confidence' => 92.9,
            'detected_at' => now(),
        ]);

        $latest = $domain->fresh()->latestFingerprint;

        $this->assertNotNull($latest);
        $this->assertSame('shopify', $latest->platform);
        $this->assertSame('92.90', $latest->confidence);
    }
}
