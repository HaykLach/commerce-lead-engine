<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use RuntimeException;

class DraftException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly bool $retryable = false, public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
