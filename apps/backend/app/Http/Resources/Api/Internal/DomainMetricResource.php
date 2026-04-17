<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DomainMetricResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'crawl_job_id' => $this->crawl_job_id,
            'platform' => $this->platform,
            'confidence' => $this->confidence,
            'country' => $this->country,
            'niche' => $this->niche,
            'business_model' => $this->business_model,
            'pages_crawled' => $this->pages_crawled,
            'product_pages' => $this->product_pages,
            'collection_pages' => $this->collection_pages,
            'blog_pages' => $this->blog_pages,
            'contact_pages' => $this->contact_pages,
            'has_cart' => $this->has_cart,
            'has_checkout' => $this->has_checkout,
            'raw_signals' => $this->raw_signals,
            'signal_summary' => $this->signal_summary,
            'measured_at' => $this->measured_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
