<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteAuditPage extends BaseModel
{
    public function pageSpeedMeasurements(): HasMany
    {
        return $this->hasMany(PageSpeedMeasurement::class);
    }

    protected $fillable = ['website_audit_id', 'url', 'url_hash', 'kind', 'status', 'attempts', 'retryable', 'final_url', 'http_status', 'fetched_at', 'evidence', 'error'];

    protected $casts = ['attempts' => 'integer', 'retryable' => 'boolean', 'fetched_at' => 'datetime', 'evidence' => 'array'];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(WebsiteAudit::class, 'website_audit_id');
    }
}
