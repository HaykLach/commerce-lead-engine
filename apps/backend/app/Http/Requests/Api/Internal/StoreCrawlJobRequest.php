<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use App\Enums\CrawlJobStatus;
use App\Enums\CrawlTriggerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreCrawlJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer', 'exists:domains,id'],
            'recrawl_of_job_id' => ['nullable', 'integer', 'exists:crawl_jobs,id'],
            'status' => ['nullable', 'string', new Enum(CrawlJobStatus::class)],
            'trigger_type' => ['nullable', 'string', new Enum(CrawlTriggerType::class)],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
            'attempt' => ['nullable', 'integer', 'min:1', 'max:100'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:100'],
            'scheduled_at' => ['nullable', 'date'],
            'started_at' => ['nullable', 'date'],
            'finished_at' => ['nullable', 'date'],
            'next_crawl_at' => ['nullable', 'date'],
            'failure_reason' => ['nullable', 'string', 'max:255'],
            'crawl_payload' => ['nullable', 'array'],
            'crawl_summary' => ['nullable', 'array'],
        ];
    }
}
