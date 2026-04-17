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
            'name' => $this->name,
            'platform' => $this->platform,
            'version' => $this->version,
            'priority' => $this->priority,
            'confidence_weight' => $this->confidence_weight,
            'rules' => $this->rules,
            'metadata' => $this->metadata,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
