<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutreachMessage extends BaseModel
{
    protected $guarded = ['id'];

    protected $casts = ['draft_revision' => 'integer', 'approved_at' => 'datetime', 'scheduled_at' => 'datetime', 'next_attempt_at' => 'datetime', 'dispatched_at' => 'datetime', 'send_started_at' => 'datetime', 'accepted_at' => 'datetime'];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(OutreachDraft::class, 'outreach_draft_id');
    }
}
