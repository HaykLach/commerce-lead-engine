<?php

declare(strict_types=1);

namespace App\Services\PageSpeed;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;

class PageSpeedBudget
{
    /** Return a delay in seconds, or zero after reserving one API request. */
    public function reserve(): int
    {
        $cache = Cache::store(config('pagespeed.cache_store'));
        $limiter = new RateLimiter($cache);
        $lock = $cache->lock('pagespeed:budget-lock', 5);
        if (! $lock->get()) {
            return 5;
        }
        try {
            $cooldown = max(0, (int) $cache->get('pagespeed:cooldown', 0) - now()->timestamp);
            if ($cooldown > 0) {
                return $cooldown;
            }
            foreach (['minute' => 'requests_per_minute', 'day' => 'requests_per_day'] as $window => $option) {
                if ($limiter->tooManyAttempts('pagespeed:'.$window, max(1, (int) config('pagespeed.'.$option)))) {
                    return max(1, $limiter->availableIn('pagespeed:'.$window));
                }
            }
            $limiter->hit('pagespeed:minute', 60);
            $limiter->hit('pagespeed:day', 86400);

            return 0;
        } finally {
            $lock->release();
        }
    }

    public function coolDown(int $seconds): void
    {
        $seconds = min(86400, max(60, $seconds));
        $cache = Cache::store(config('pagespeed.cache_store'));
        $cache->lock('pagespeed:budget-lock', 5)->block(2, function () use ($cache, $seconds): void {
            $until = max((int) $cache->get('pagespeed:cooldown', 0), now()->timestamp + $seconds);
            $cache->put('pagespeed:cooldown', $until, $until - now()->timestamp);
        });
    }
}
