<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeadScoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'crawl_job_id' => $this->crawl_job_id,
            'domain_metric_id' => $this->domain_metric_id,
            'score_config_id' => $this->score_config_id,
            'opportunity_score' => $this->opportunity_score,
            'grade' => $this->grade?->value,
            'score_breakdown' => $this->score_breakdown,
            'score_reasons' => $this->score_reasons,
            'version' => $this->version,
            'computed_at' => $this->computed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
