<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use Illuminate\Foundation\Http\FormRequest;

class StoreDomainMetricRequest extends FormRequest
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
            'platform' => ['nullable', 'string', 'max:120'],
            'confidence' => ['nullable', 'numeric', 'between:0,100'],
            'country' => ['nullable', 'string', 'size:2'],
            'niche' => ['nullable', 'string', 'max:120'],
            'business_model' => ['nullable', 'string', 'max:120'],
            'pages_crawled' => ['nullable', 'integer', 'min:0'],
            'product_pages' => ['nullable', 'integer', 'min:0'],
            'collection_pages' => ['nullable', 'integer', 'min:0'],
            'blog_pages' => ['nullable', 'integer', 'min:0'],
            'contact_pages' => ['nullable', 'integer', 'min:0'],
            'has_cart' => ['sometimes', 'boolean'],
            'has_checkout' => ['sometimes', 'boolean'],
            'raw_signals' => ['nullable', 'array'],
            'signal_summary' => ['nullable', 'array'],
            'measured_at' => ['nullable', 'date'],
        ];
    }
}
