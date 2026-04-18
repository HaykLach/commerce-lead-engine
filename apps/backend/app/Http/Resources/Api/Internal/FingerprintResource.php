<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FingerprintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'name' => $this->name,
            'platform' => $this->platform,
            'confidence' => $this->confidence,
            'version' => $this->version,
            'priority' => $this->priority,
            'confidence_weight' => $this->confidence_weight,
            'rules' => $this->rules,
            'frontend_stack' => $this->frontend_stack,
            'signals' => $this->signals,
            'raw_payload' => $this->raw_payload,
            'whatweb_payload' => $this->whatweb_payload,
            'metadata' => $this->metadata,
            'detected_at' => $this->detected_at,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
