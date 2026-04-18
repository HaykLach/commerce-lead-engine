<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class Fingerprint extends BaseModel
{
    use HasFactory;

    protected $table = 'fingerprints';

    protected $fillable = [
        'name',
        'platform',
        'version',
        'priority',
        'confidence_weight',
        'rules',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'priority' => 'integer',
        'confidence_weight' => 'decimal:2',
        'rules' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];
}
