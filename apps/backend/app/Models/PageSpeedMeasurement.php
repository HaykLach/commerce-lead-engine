<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageSpeedMeasurement extends BaseModel
{
    protected $fillable = ['website_audit_page_id', 'strategy', 'active_key', 'requested_url', 'final_url', 'status', 'attempts', 'lease_token', 'lease_until', 'next_attempt_at', 'measured_at', 'finished_at', 'expires_at', 'http_status', 'error_code', 'error', 'performance_score', 'lighthouse_version', 'result'];

    protected $casts = ['attempts' => 'integer', 'performance_score' => 'integer', 'lease_until' => 'datetime', 'next_attempt_at' => 'datetime', 'measured_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime', 'result' => 'array'];

    public function page(): BelongsTo
    {
        return $this->belongsTo(WebsiteAuditPage::class, 'website_audit_page_id');
    }
}
