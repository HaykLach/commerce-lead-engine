<?php

declare(strict_types=1);

namespace App\Models;

class LeadScore extends BaseModel
{
    protected $table = 'lead_scores';

    protected $casts = [
        'score_breakdown' => 'array',
        'computed_at' => 'datetime',
    ];
}
