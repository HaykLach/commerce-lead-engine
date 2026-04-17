<?php

declare(strict_types=1);

namespace App\Models;

class Fingerprint extends BaseModel
{
    protected $table = 'fingerprints';

    protected $casts = [
        'rules' => 'array',
        'is_active' => 'boolean',
    ];
}
