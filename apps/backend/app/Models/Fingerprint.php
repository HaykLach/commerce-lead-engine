<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fingerprint extends BaseModel
{
    use HasFactory;

    protected $table = 'fingerprints';

    protected $fillable = [
        'domain_id',
        'name',
        'platform',
        'confidence',
        'version',
        'priority',
        'confidence_weight',
        'rules',
        'frontend_stack',
        'signals',
        'raw_payload',
        'whatweb_payload',
        'detected_at',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'domain_id' => 'integer',
        'confidence' => 'decimal:2',
        'priority' => 'integer',
        'confidence_weight' => 'decimal:2',
        'rules' => 'array',
        'frontend_stack' => 'array',
        'signals' => 'array',
        'raw_payload' => 'array',
        'whatweb_payload' => 'array',
        'detected_at' => 'datetime',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
