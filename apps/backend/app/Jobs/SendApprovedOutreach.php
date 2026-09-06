<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Outreach\MessageDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendApprovedOutreach implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $messageId) {}

    public function handle(MessageDelivery $delivery): void
    {
        $delivery->run($this->messageId);
    }
}
