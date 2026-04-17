<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\Internal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrawlJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'domain_id' => $this->domain_id,
            'recrawl_of_job_id' => $this->recrawl_of_job_id,
            'status' => $this->status?->value,
            'trigger_type' => $this->trigger_type?->value,
            'priority' => $this->priority,
            'attempt' => $this->attempt,
            'max_attempts' => $this->max_attempts,
            'scheduled_at' => $this->scheduled_at,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'next_crawl_at' => $this->next_crawl_at,
            'failure_reason' => $this->failure_reason,
            'crawl_payload' => $this->crawl_payload,
            'crawl_summary' => $this->crawl_summary,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
