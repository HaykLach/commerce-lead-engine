<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomainContact extends BaseModel
{
    protected $fillable = ['domain_id', 'email', 'suggested_purpose', 'purpose_override', 'review_status', 'is_manual', 'first_seen_at', 'last_seen_at'];

    protected $casts = ['is_manual' => 'boolean', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(DomainContactSource::class);
    }

    public function effectivePurpose(): string
    {
        return $this->purpose_override ?? $this->suggested_purpose;
    }
}
