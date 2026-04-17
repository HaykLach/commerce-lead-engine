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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
