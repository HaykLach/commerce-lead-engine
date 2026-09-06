<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Enrichment\WebsiteAuditRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunWebsiteAudit implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 70;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $auditId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WebsiteAuditRunner $runner): void
    {
        $runner->run($this->auditId);
    }

    public function failed(?Throwable $exception): void
    {
        app(WebsiteAuditRunner::class)->fail($this->auditId, 'The queue attempt failed or timed out. Retry the audit after checking the worker logs.');
    }
}
