<?php

declare(strict_types=1);

namespace App\Services\Domain;

use App\Models\Domain;
use App\Models\DomainMetric;

class DomainEnrichmentPipeline
{
    public function run(Domain $domain): void
    {
        $this->crawlHomepage($domain);
        $this->collectDomainMetrics($domain);
        $this->guessPlatform($domain);
        $this->guessNiche($domain);
        $this->calculateConfidenceScore($domain);
    }

    protected function crawlHomepage(Domain $domain): void
    {
        $domain->forceFill([
            'last_crawled_at' => now(),
        ])->save();
    }

    protected function collectDomainMetrics(Domain $domain): void
    {
        DomainMetric::query()->create([
            'domain_id' => $domain->id,
            'platform' => $domain->platform,
            'confidence' => (float) ($domain->confidence ?? 0),
            'country' => $domain->country,
            'niche' => $domain->niche,
            'business_model' => $domain->business_model,
            'pages_crawled' => 1,
            'product_pages' => 0,
            'collection_pages' => 0,
            'blog_pages' => 0,
            'contact_pages' => 0,
            'has_cart' => false,
            'has_checkout' => false,
            'raw_signals' => [
                'has_cart' => false,
                'has_checkout' => false,
                'product_pages_count' => 0,
                'blog_pages_count' => 0,
                'contact_page_found' => false,
                'social_links_found' => false,
            ],
            'signal_summary' => [
                'contact_page_found' => false,
                'social_links_found' => false,
            ],
            'measured_at' => now(),
        ]);
    }

    protected function guessPlatform(Domain $domain): void
    {
        $domain->forceFill([
            'platform' => $domain->platform ?? 'unknown',
        ])->save();
    }

    protected function guessNiche(Domain $domain): void
    {
        $domain->forceFill([
            'niche' => $domain->niche ?? 'general',
        ])->save();
    }

    protected function calculateConfidenceScore(Domain $domain): void
    {
        $domain->refresh();

        $domain->forceFill([
            'confidence' => $domain->platform !== null && $domain->niche !== null ? 50 : 0,
        ])->save();
    }
}
