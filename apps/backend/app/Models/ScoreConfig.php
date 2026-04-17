<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScoreConfig extends BaseModel
{
    use HasFactory;

    protected $table = 'score_configs';

    protected $fillable = [
        'name',
        'version',
        'is_active',
        'weights',
        'thresholds',
        'rules',
        'metadata',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'weights' => 'array',
        'thresholds' => 'array',
        'rules' => 'array',
        'metadata' => 'array',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function leadScores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }
}
