<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteAudit extends BaseModel
{
    protected $fillable = ['domain_id', 'active_domain_id', 'status', 'attempts', 'lease_token', 'lease_until', 'started_at', 'finished_at', 'expires_at', 'error', 'summary'];

    protected $casts = ['attempts' => 'integer', 'lease_until' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'expires_at' => 'datetime', 'summary' => 'array'];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(WebsiteAuditPage::class)->orderBy('id');
    }
}
