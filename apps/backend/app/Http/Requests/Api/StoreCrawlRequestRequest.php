<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreCrawlRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'size:2'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'min_ecommerce_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'exclude_existing' => ['nullable', 'boolean'],
        ];
    }
}
