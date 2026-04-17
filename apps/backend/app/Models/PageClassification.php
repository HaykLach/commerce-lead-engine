<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageType;

class PageClassification extends BaseModel
{
    protected $table = 'page_classifications';

    protected $casts = [
        'page_type' => PageType::class,
        'signals' => 'array',
    ];
}
