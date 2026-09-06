<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use Illuminate\Container\Attributes\Bind;

#[Bind(OpenAiDraftGenerator::class)]
interface DraftGenerator
{
    /** Return the response envelope; validation and composition happen separately. */
    public function generate(array $prompt, string $model): array;
}
