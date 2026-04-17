<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainSource extends BaseModel
{
    use HasFactory;

    protected $table = 'domain_sources';

    protected $fillable = [
        'domain_id',
        'source_type',
        'source_name',
        'source_reference',
        'discovered_at',
        'context',
    ];

    protected $casts = [
        'source_type' => DomainSourceType::class,
        'discovered_at' => 'datetime',
        'context' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
