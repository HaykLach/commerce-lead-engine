<?php

declare(strict_types=1);

namespace App\Services\PageSpeed;

use App\Models\PageSpeedMeasurement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class PageSpeedRunner
{
    public function __construct(private readonly PageSpeedClient $client, private readonly PageSpeedResultParser $parser, private readonly PageSpeedBudget $budget) {}

    public function run(int $id): ?int
    {
        $token = (string) Str::uuid();
        $measurement = DB::transaction(function () use ($id, $token): ?PageSpeedMeasurement {
            $row = PageSpeedMeasurement::query()->lockForUpdate()->find($id);
            if ($row === null || $row->active_key === null || $row->lease_until?->isFuture()) {
                return null;
            }
            $row->update(['lease_token' => $token, 'lease_until' => now()->addSeconds(85)]);

            return $row;
        });
        if ($measurement === null) {
            return null;
        }
        $attempted = false;
        try {
            if (! config('pagespeed.enabled')) {
                throw new PageSpeedException('disabled', 'PageSpeed collection was disabled before this request ran.');
            }
            if ($measurement->attempts >= 3) {
                throw new PageSpeedException('attempt_limit', 'The measurement exceeded its attempt limit.');
            }
            if ($measurement->next_attempt_at?->isFuture()) {
                return $this->defer($measurement, $token, max(1, $measurement->next_attempt_at->timestamp - now()->timestamp));
            }
            $delay = $this->budget->reserve();
            if ($delay > 0) {
                return $this->defer($measurement, $token, $delay);
            }
            if (! $this->persist($id, $token, ['status' => 'running', 'attempts' => $measurement->attempts + 1, 'error' => null, 'error_code' => null])) {
                return null;
            }
            $measurement->refresh();
            $attempted = true;
            $domain = $measurement->page->audit->domain->normalized_domain;
            $data = $this->client->measure($measurement->requested_url, $domain, $measurement->strategy);
            $parsed = $this->parser->parse($data, $measurement->requested_url, $domain, $measurement->strategy);
            $expires = $parsed['measured_at']->addDays(max(1, (int) config('pagespeed.fresh_days')));
            if ($expires->isPast()) {
                throw new PageSpeedException('stale_result', 'PageSpeed returned a measurement older than the freshness window.');
            }
            $this->persist($id, $token, $parsed + ['status' => 'completed', 'active_key' => null, 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => null, 'finished_at' => now(), 'expires_at' => $expires, 'http_status' => 200, 'error' => null, 'error_code' => null]);

            return null;
        } catch (Throwable $exception) {
            $failure = $exception instanceof PageSpeedException ? $exception : new PageSpeedException('collector_failed', 'The PageSpeed collector could not complete this measurement.', true);
            if ($failure->httpStatus === 429 || $failure->reason === 'quota_exceeded') {
                $this->budget->coolDown($failure->retryAfter);
            }
            // A crash before the request counter is saved must still have bounded recovery.
            $attempts = min(3, $measurement->attempts + ($attempted ? 0 : 1));
            $retry = $failure->retryable && $attempts < 3;
            $delay = max($attempts === 1 ? 60 : 300, $failure->retryAfter);
            $this->persist($id, $token, ['attempts' => $attempts, 'status' => $retry ? 'queued' : 'failed',
                'active_key' => $retry ? $measurement->active_key : null, 'lease_token' => null, 'lease_until' => null,
                'next_attempt_at' => $retry ? now()->addSeconds($delay) : null, 'finished_at' => $retry ? null : now(),
                'expires_at' => $retry ? null : now()->addHours(max(1, (int) config('pagespeed.failure_retry_hours'))),
                'error_code' => $failure->reason, 'error' => $failure->getMessage(), 'http_status' => $failure->httpStatus]);

            return $retry ? $delay : null;
        }
    }

    private function defer(PageSpeedMeasurement $measurement, string $token, int $delay): int
    {
        $this->persist($measurement->id, $token, ['status' => 'queued', 'lease_token' => null, 'lease_until' => null, 'next_attempt_at' => now()->addSeconds($delay)]);

        return $delay;
    }

    private function persist(int $id, string $token, array $attributes): bool
    {
        return DB::transaction(function () use ($id, $token, $attributes): bool {
            $row = PageSpeedMeasurement::query()->lockForUpdate()->find($id);
            if ($row === null || $row->lease_token !== $token || $row->active_key === null) {
                return false;
            }

            return $row->update($attributes);
        });
    }
}
