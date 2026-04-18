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
        'product_page_found',
        'category_page_found',
        'cart_page_found',
        'checkout_page_found',
        'sample_product_url',
        'sample_category_url',
        'sample_cart_url',
        'sample_checkout_url',
        'product_count_guess',
        'product_count_bucket',
        'classification_metadata',
        'classified_at',
    ];

    protected $casts = [
        'page_type' => PageType::class,
        'confidence' => 'decimal:2',
        'signals' => 'array',
        'features' => 'array',
        'product_page_found' => 'boolean',
        'category_page_found' => 'boolean',
        'cart_page_found' => 'boolean',
        'checkout_page_found' => 'boolean',
        'product_count_guess' => 'integer',
        'classification_metadata' => 'array',
        'classified_at' => 'datetime',
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
