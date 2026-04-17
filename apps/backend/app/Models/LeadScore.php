<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\ScoreReasonsCast;
use App\Enums\LeadGrade;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadScore extends BaseModel
{
    use HasFactory;

    protected $table = 'lead_scores';

    protected $fillable = [
        'domain_id',
        'crawl_job_id',
        'domain_metric_id',
        'score_config_id',
        'opportunity_score',
        'grade',
        'score_breakdown',
        'score_reasons',
        'version',
        'computed_at',
    ];

    protected $casts = [
        'opportunity_score' => 'decimal:2',
        'grade' => LeadGrade::class,
        'score_breakdown' => 'array',
        'score_reasons' => ScoreReasonsCast::class,
        'computed_at' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }

    public function domainMetric(): BelongsTo
    {
        return $this->belongsTo(DomainMetric::class);
    }

    public function scoreConfig(): BelongsTo
    {
        return $this->belongsTo(ScoreConfig::class);
    }
}
