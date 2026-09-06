<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainContactSource extends BaseModel
{
    protected $fillable = ['domain_contact_id', 'url', 'url_hash', 'methods', 'original_hint', 'page_classification_id', 'first_seen_at', 'last_seen_at'];

    protected $casts = ['methods' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(DomainContact::class, 'domain_contact_id');
    }
}
