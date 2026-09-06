<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PageSpeed\PageSpeedRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunPageSpeedMeasurement implements ShouldQueue
{
    use Queueable;

    // Rate-limit releases do not count as API attempts; the database caps actual attempts at three.
    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 70;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $measurementId) {}

    public function handle(PageSpeedRunner $runner): void
    {
        $delay = $runner->run($this->measurementId);
        if ($delay !== null) {
            $this->release($delay);
        }
    }
}
