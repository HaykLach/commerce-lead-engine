<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use RuntimeException;

class FetchException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
