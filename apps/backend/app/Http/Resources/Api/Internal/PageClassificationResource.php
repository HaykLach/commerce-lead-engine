<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageClassificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'crawl_job_id' => $this->crawl_job_id,
            'url' => $this->url,
            'canonical_url' => $this->canonical_url,
            'page_type' => $this->page_type?->value,
            'confidence' => $this->confidence,
            'signals' => $this->signals,
            'features' => $this->features,
            'product_page_found' => $this->product_page_found,
            'category_page_found' => $this->category_page_found,
            'cart_page_found' => $this->cart_page_found,
            'checkout_page_found' => $this->checkout_page_found,
            'sample_product_url' => $this->sample_product_url,
            'sample_category_url' => $this->sample_category_url,
            'sample_cart_url' => $this->sample_cart_url,
            'sample_checkout_url' => $this->sample_checkout_url,
            'product_count_guess' => $this->product_count_guess,
            'product_count_bucket' => $this->product_count_bucket,
            'classification_metadata' => $this->classification_metadata,
            'classified_at' => $this->classified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
