<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use App\Enums\DomainStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpsertDiscoveredDomainRequest extends FormRequest
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
            'status' => ['nullable', 'string', new Enum(DomainStatus::class)],
            'platform' => ['nullable', 'string', 'max:120'],
            'confidence' => ['nullable', 'numeric', 'between:0,100'],
            'country' => ['nullable', 'string', 'size:2'],
            'niche' => ['nullable', 'string', 'max:120'],
            'business_model' => ['nullable', 'string', 'max:120'],
            'metadata' => ['nullable', 'array'],
            'first_seen_at' => ['nullable', 'date'],
            'last_seen_at' => ['nullable', 'date'],
            'last_crawled_at' => ['nullable', 'date'],
        ];
    }
}
