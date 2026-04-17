<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use App\Enums\PageType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StorePageClassificationRequest extends FormRequest
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
            'url' => ['required', 'url', 'max:2048'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'page_type' => ['required', 'string', new Enum(PageType::class)],
            'confidence' => ['nullable', 'numeric', 'between:0,100'],
            'signals' => ['nullable', 'array'],
            'features' => ['nullable', 'array'],
        ];
    }
}
