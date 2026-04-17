<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CrawlJobStatus;
use App\Enums\CrawlTriggerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlJob extends BaseModel
{
    use HasFactory;

    protected $table = 'crawl_jobs';

    protected $fillable = [
        'domain_id',
        'recrawl_of_job_id',
        'status',
        'trigger_type',
        'priority',
        'attempt',
        'max_attempts',
        'scheduled_at',
        'started_at',
        'finished_at',
        'next_crawl_at',
        'failure_reason',
        'crawl_payload',
        'crawl_summary',
    ];

    protected $casts = [
        'status' => CrawlJobStatus::class,
        'trigger_type' => CrawlTriggerType::class,
        'priority' => 'integer',
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'next_crawl_at' => 'datetime',
        'crawl_payload' => 'array',
        'crawl_summary' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function parentJob(): BelongsTo
    {
        return $this->belongsTo(self::class, 'recrawl_of_job_id');
    }

    public function recrawlChildren(): HasMany
    {
        return $this->hasMany(self::class, 'recrawl_of_job_id');
    }

    public function pageClassifications(): HasMany
    {
        return $this->hasMany(PageClassification::class);
    }

    public function domainMetrics(): HasMany
    {
        return $this->hasMany(DomainMetric::class);
    }

    public function leadScores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }
}
