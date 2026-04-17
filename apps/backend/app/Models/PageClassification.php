<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageClassification extends BaseModel
{
    use HasFactory;

    protected $table = 'page_classifications';

    protected $fillable = [
        'domain_id',
        'crawl_job_id',
        'url',
        'canonical_url',
        'page_type',
        'confidence',
        'signals',
        'features',
    ];

    protected $casts = [
        'page_type' => PageType::class,
        'confidence' => 'decimal:2',
        'signals' => 'array',
        'features' => 'array',
    ];

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    public function crawlJob(): BelongsTo
    {
        return $this->belongsTo(CrawlJob::class);
    }
}
