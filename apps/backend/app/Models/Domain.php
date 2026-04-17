<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainStatus;

class Domain extends BaseModel
{
    protected $table = 'domains';

    protected $casts = [
        'status' => DomainStatus::class,
        'metadata' => 'array',
        'last_crawled_at' => 'datetime',
    ];
}
