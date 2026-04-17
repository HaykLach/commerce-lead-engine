<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DomainMetric extends BaseModel
{
    use HasFactory;

    protected $table = 'domain_metrics';

    protected $fillable = [
        'domain_id',
        'crawl_job_id',
        'platform',
        'confidence',
        'country',
        'niche',
        'business_model',
        'pages_crawled',
        'product_pages',
        'collection_pages',
        'blog_pages',
        'contact_pages',
        'has_cart',
        'has_checkout',
        'raw_signals',
        'signal_summary',
        'measured_at',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'pages_crawled' => 'integer',
        'product_pages' => 'integer',
        'collection_pages' => 'integer',
        'blog_pages' => 'integer',
        'contact_pages' => 'integer',
        'has_cart' => 'boolean',
        'has_checkout' => 'boolean',
        'raw_signals' => 'array',
        'signal_summary' => 'array',
        'measured_at' => 'datetime',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }

    public function leadScores(): HasMany
    {
        return $this->hasMany(LeadScore::class);
    }
}
