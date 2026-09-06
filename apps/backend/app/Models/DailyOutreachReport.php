<?php

declare(strict_types=1);

namespace App\Models;

class DailyOutreachReport extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = ['summary' => 'array', 'dispatched_at' => 'datetime', 'notification_started_at' => 'datetime'];
}
