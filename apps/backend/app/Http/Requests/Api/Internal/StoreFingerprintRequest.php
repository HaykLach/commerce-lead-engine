<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use Illuminate\Foundation\Http\FormRequest;

class StoreFingerprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain_id' => ['nullable', 'integer', 'exists:domains,id'],
            'domain' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:120'],
            'version' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'confidence_weight' => ['nullable', 'numeric', 'between:0,100'],
            'confidence' => ['nullable', 'numeric', 'between:0,100'],
            'rules' => ['nullable', 'array'],
            'frontend_stack' => ['nullable', 'array'],
            'signals' => ['nullable', 'array'],
            'raw_payload' => ['nullable', 'array'],
            'whatweb_payload' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'detected_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (!$this->filled('domain_id') && !$this->filled('domain')) {
                $validator->errors()->add('domain', 'Either domain_id or domain is required.');
            }
        });
    }
}
