<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use App\Enums\DomainSourceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class IngestDiscoveredDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:255'],
            'normalized_domain' => ['nullable', 'string', 'max:255'],
            'source_type' => ['required', 'string', new Enum(DomainSourceType::class)],
            'source_name' => ['nullable', 'string', 'max:255'],
            'source_reference' => ['nullable', 'string', 'max:2048'],
            'source_context' => ['nullable', 'array'],
            'priority_homepage_fetch' => ['nullable', 'integer', 'min:1', 'max:10'],
            'priority_page_classification' => ['nullable', 'integer', 'min:1', 'max:10'],
            'enqueue_homepage_fetch' => ['nullable', 'boolean'],
            'enqueue_page_classification' => ['nullable', 'boolean'],
        ];
    }
}
