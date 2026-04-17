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
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'max:120'],
            'version' => ['nullable', 'string', 'max:120'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'confidence_weight' => ['nullable', 'numeric', 'between:0,100'],
            'rules' => ['required', 'array'],
            'metadata' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
