<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutreachDraft extends BaseModel
{
    protected $fillable = ['domain_id', 'website_audit_id', 'domain_contact_id', 'active_domain_id', 'recipient_email', 'status', 'input_hash', 'evidence', 'prompt', 'prompt_version', 'model', 'response_model', 'response_id', 'usage', 'selected_issue_ids', 'subject', 'body', 'generated_subject', 'generated_body', 'body_html', 'revision', 'edited_at', 'attempts', 'lease_token', 'lease_until', 'next_attempt_at', 'finished_at', 'error_code', 'error'];

    protected $casts = ['evidence' => 'array', 'prompt' => 'array', 'usage' => 'array', 'selected_issue_ids' => 'array', 'revision' => 'integer', 'attempts' => 'integer', 'edited_at' => 'datetime', 'lease_until' => 'datetime', 'next_attempt_at' => 'datetime', 'finished_at' => 'datetime'];

    public function messages(): HasMany
    {
        return $this->hasMany(OutreachMessage::class);
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(WebsiteAudit::class, 'website_audit_id');
    }
}
