<?php

declare(strict_types=1);

namespace App\Services\PageSpeed;

use RuntimeException;

class PageSpeedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly bool $retryable = false, public readonly ?int $httpStatus = null, public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }
}
