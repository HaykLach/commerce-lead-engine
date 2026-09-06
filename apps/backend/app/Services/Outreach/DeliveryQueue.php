<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use Illuminate\Validation\ValidationException;

class DeliveryQueue
{
    public static function connection(): string
    {
        $connection = (string) config('outreach_delivery.connection');
        if (! in_array(config("queue.connections.$connection.driver"), ['database', 'redis'], true)
            || (int) config("queue.connections.$connection.retry_after") <= 60) {
            throw ValidationException::withMessages(['draft' => 'Use a database or Redis delivery queue with retry_after greater than 60 seconds.']);
        }

        return $connection;
    }
}
