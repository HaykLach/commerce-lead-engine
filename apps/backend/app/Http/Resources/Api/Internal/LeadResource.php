<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'lead_score_id' => $this->id,
            'opportunity_score' => $this->opportunity_score,
            'grade' => $this->grade?->value,
            'computed_at' => $this->computed_at,
            'version' => $this->version,
            'domain' => [
                'id' => $this->domain?->id,
                'domain' => $this->domain?->domain,
                'normalized_domain' => $this->domain?->normalized_domain,
                'status' => $this->domain?->status?->value,
                'platform' => $this->domain?->platform,
                'country' => $this->domain?->country,
                'niche' => $this->domain?->niche,
                'business_model' => $this->domain?->business_model,
            ],
            'metric' => [
                'id' => $this->domainMetric?->id,
                'pages_crawled' => $this->domainMetric?->pages_crawled,
                'product_pages' => $this->domainMetric?->product_pages,
                'collection_pages' => $this->domainMetric?->collection_pages,
                'has_cart' => $this->domainMetric?->has_cart,
                'has_checkout' => $this->domainMetric?->has_checkout,
            ],
            'score_breakdown' => $this->score_breakdown,
            'score_reasons' => $this->score_reasons,
        ];
    }
}
