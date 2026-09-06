<?php

declare(strict_types=1);

namespace App\Services\InternalApi;

use App\Enums\PageType;
use App\Models\Domain;
use App\Models\PageClassification;
use App\Services\Contacts\ContactIngestionService;
use Illuminate\Support\Facades\DB;

class PageClassificationIngestionService
{
    public function __construct(private readonly ContactIngestionService $contacts) {}

    public function store(array $payload): PageClassification
    {
        return DB::transaction(fn (): PageClassification => $this->storeWithContacts($payload), 3);
    }

    private function storeWithContacts(array $payload): PageClassification
    {
        $resolvedDomainId = $this->resolveDomainId($payload);

        $classification = PageClassification::query()->create([
            'domain_id' => $resolvedDomainId,
            'crawl_job_id' => $payload['crawl_job_id'] ?? null,
            'url' => $payload['url'] ?? $payload['sample_product_url'] ?? $payload['sample_category_url'] ?? $payload['sample_cart_url'] ?? $payload['sample_checkout_url'] ?? sprintf('https://%s', $payload['domain'] ?? ''),
            'canonical_url' => $payload['canonical_url'] ?? null,
            'page_type' => $payload['page_type'] ?? PageType::Unknown->value,
            'confidence' => $payload['confidence'] ?? 0,
            'signals' => $payload['signals'] ?? null,
            'features' => $payload['features'] ?? null,
            'product_page_found' => (bool) ($payload['product_page_found'] ?? false),
            'category_page_found' => (bool) ($payload['category_page_found'] ?? false),
            'cart_page_found' => (bool) ($payload['cart_page_found'] ?? false),
            'checkout_page_found' => (bool) ($payload['checkout_page_found'] ?? false),
            'is_ecommerce' => isset($payload['is_ecommerce']) ? (bool) $payload['is_ecommerce'] : null,
            'detected_language' => $payload['detected_language'] ?? null,
            'sample_product_url' => $payload['sample_product_url'] ?? null,
            'sample_category_url' => $payload['sample_category_url'] ?? null,
            'sample_cart_url' => $payload['sample_cart_url'] ?? null,
            'sample_checkout_url' => $payload['sample_checkout_url'] ?? null,
            'product_count_guess' => $payload['product_count_guess'] ?? null,
            'product_count_bucket' => $payload['product_count_bucket'] ?? null,
            'classification_metadata' => $payload['classification_metadata'] ?? null,
            'classified_at' => $payload['classified_at'] ?? now()->toIso8601String(),
        ]);

        $this->contacts->ingest($classification);

        return $classification;
    }

    private function resolveDomainId(array $payload): int
    {
        if (isset($payload['domain_id'])) {
            return (int) $payload['domain_id'];
        }

        $domain = Domain::query()
            ->where('normalized_domain', strtolower((string) $payload['domain']))
            ->orWhere('domain', (string) $payload['domain'])
            ->firstOrFail();

        return (int) $domain->id;
    }
}
