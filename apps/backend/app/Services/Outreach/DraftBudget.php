<?php

declare(strict_types=1);

namespace App\Services\Outreach;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

class DraftBudget
{
    public function reserve(): int
    {
        $cache = Cache::store(config('outreach.cache_store'));
        $limiter = new RateLimiter($cache);
        $lock = $cache->lock('outreach:budget-lock', 5);
        if (! $lock->get()) {
            return 5;
        }
        try {
            foreach (['minute' => 3, 'day' => max(1, (int) config('outreach.requests_per_day'))] as $window => $limit) {
                if ($limiter->tooManyAttempts('outreach:'.$window, $limit)) {
                    return max(1, $limiter->availableIn('outreach:'.$window));
                }
            }
            $limiter->hit('outreach:minute', 60);
            $limiter->hit('outreach:day', 86400);

            return 0;
        } finally {
            $lock->release();
        }
    }
}
