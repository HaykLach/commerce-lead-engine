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
            'domain_id' => ['nullable', 'integer', 'exists:domains,id', 'required_without:domain'],
            'domain' => ['nullable', 'string', 'max:255', 'required_without:domain_id'],
            'crawl_job_id' => ['nullable', 'integer', 'exists:crawl_jobs,id'],
            'url' => ['nullable', 'url', 'max:2048'],
            'canonical_url' => ['nullable', 'url', 'max:2048'],
            'page_type' => ['nullable', 'string', new Enum(PageType::class)],
            'confidence' => ['nullable', 'numeric', 'between:0,100'],
            'signals' => ['nullable', 'array'],
            'features' => ['nullable', 'array'],
            'product_page_found' => ['nullable', 'boolean'],
            'category_page_found' => ['nullable', 'boolean'],
            'cart_page_found' => ['nullable', 'boolean'],
            'checkout_page_found' => ['nullable', 'boolean'],
            'sample_product_url' => ['nullable', 'url', 'max:2048'],
            'sample_category_url' => ['nullable', 'url', 'max:2048'],
            'sample_cart_url' => ['nullable', 'url', 'max:2048'],
            'sample_checkout_url' => ['nullable', 'url', 'max:2048'],
            'product_count_guess' => ['nullable', 'integer', 'min:1'],
            'product_count_bucket' => ['nullable', 'string', 'in:1-50,51-200,201-1000,1000+'],
            'classification_metadata' => ['nullable', 'array'],
            'classified_at' => ['nullable', 'date'],
        ];
    }
}
