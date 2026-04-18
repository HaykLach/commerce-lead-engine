<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Domain extends BaseModel
{
    use HasFactory;

    protected $table = 'domains';

    protected $fillable = [
        'domain',
        'normalized_domain',
        'status',
        'platform',
        'confidence',
        'country',
        'niche',
        'business_model',
        'first_seen_at',
        'last_seen_at',
        'last_crawled_at',
        'metadata',
    ];

    protected $casts = [
        'status' => DomainStatus::class,
        'confidence' => 'decimal:2',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_crawled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function sources(): HasMany
    {
        return $this->hasMany(DomainSource::class);
    }

    public function crawlJobs(): HasMany
    {
        return $this->hasMany(CrawlJob::class);
    }

    public function pageClassifications(): HasMany
    {
        return $this->hasMany(PageClassification::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(DomainMetric::class);
    }

    public function fingerprints(): HasMany
    {
        return $this->hasMany(DomainFingerprint::class);
    }

    public function latestFingerprint(): HasOne
    {
        return $this->hasOne(DomainFingerprint::class)->latestOfMany('detected_at');
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(DomainMetric::class)->latestOfMany('measured_at');
    }

    public function leadScores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }

    public function latestLeadScore(): HasOne
    {
        return $this->hasOne(LeadScore::class)->latestOfMany('computed_at');
    }
}
