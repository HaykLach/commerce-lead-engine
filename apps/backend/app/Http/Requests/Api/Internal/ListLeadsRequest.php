<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use App\Enums\LeadGrade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class ListLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'domain' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', new Enum(LeadGrade::class)],
            'platform' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'size:2'],
            'niche' => ['nullable', 'string', 'max:120'],
            'business_model' => ['nullable', 'string', 'max:120'],
            'min_score' => ['nullable', 'numeric', 'between:0,100'],
            'max_score' => ['nullable', 'numeric', 'between:0,100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
