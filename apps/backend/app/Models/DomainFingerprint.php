<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainFingerprint extends BaseModel
{
    use HasFactory;

    protected $table = 'domain_fingerprints';

    protected $fillable = [
        'domain_id',
        'platform',
        'confidence',
        'frontend_stack',
        'signals',
        'raw_payload',
        'whatweb_payload',
        'detected_at',
    ];

    protected $casts = [
        'domain_id' => 'integer',
        'confidence' => 'decimal:2',
        'frontend_stack' => 'array',
        'signals' => 'array',
        'raw_payload' => 'array',
        'whatweb_payload' => 'array',
        'detected_at' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
