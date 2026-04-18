<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Internal;

use Illuminate\Foundation\Http\FormRequest;

class FailCrawlJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'error' => ['required', 'string', 'max:255'],
        ];
    }
}
