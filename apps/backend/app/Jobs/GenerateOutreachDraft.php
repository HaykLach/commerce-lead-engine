<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Outreach\DraftRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateOutreachDraft implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 70;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $draftId) {}

    public function handle(DraftRunner $runner): void
    {
        $delay = $runner->run($this->draftId);
        if ($delay !== null) {
            $this->release($delay);
        }
    }
}
