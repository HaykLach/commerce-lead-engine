<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use App\Enums\LeadGrade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreLeadScoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain_id' => ['required', 'integer', 'exists:domains,id'],
            'crawl_job_id' => ['nullable', 'integer', 'exists:crawl_jobs,id'],
            'domain_metric_id' => ['nullable', 'integer', 'exists:domain_metrics,id'],
            'score_config_id' => ['nullable', 'integer', 'exists:score_configs,id'],
            'opportunity_score' => ['required', 'numeric', 'between:0,100'],
            'grade' => ['nullable', 'string', new Enum(LeadGrade::class)],
            'score_breakdown' => ['nullable', 'array'],
            'score_reasons' => ['nullable', 'array'],
            'version' => ['nullable', 'string', 'max:32'],
            'computed_at' => ['nullable', 'date'],
        ];
    }
}
