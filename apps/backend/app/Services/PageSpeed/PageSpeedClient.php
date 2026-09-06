<?php

declare(strict_types=1);

namespace App\Services\PageSpeed;

use App\Services\Enrichment\AuditUrl;
use App\Services\Enrichment\FetchException;
use App\Services\Enrichment\PublicDnsResolver;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Http;
use Throwable;

class PageSpeedClient
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public function __construct(private readonly PublicDnsResolver $dns) {}

    public function measure(string $url, string $domain, string $strategy): array
    {
        if (AuditUrl::normalize($url) !== $url || ! AuditUrl::sameSite($url, $domain) || ! in_array($strategy, ['mobile', 'desktop'], true)) {
            throw new PageSpeedException('invalid_target', 'The selected measurement URL or device is invalid.');
        }
        try {
            $this->dns->resolve(parse_url($url, PHP_URL_HOST));
        } catch (FetchException $exception) {
            throw new PageSpeedException('unsafe_target', 'The website address could not be validated as public.', $exception->retryable);
        }
        $limit = min(8_000_000, max(1024, (int) config('pagespeed.max_response_bytes')));
        $stream = Utils::streamFor(fopen('php://temp/maxmemory:1048576', 'w+'));
        $size = 0;
        $sink = FnStream::decorate($stream, ['write' => function (string $chunk) use ($stream, &$size, $limit): int {
            $size += strlen($chunk);
            if ($size > $limit) {
                throw new PageSpeedException('response_too_large', 'The PageSpeed response exceeded the size limit.');
            }

            return $stream->write($chunk);
        }]);
        try {
            $query = ['url' => $url, 'strategy' => $strategy, 'category' => 'performance', 'locale' => 'en'];
            if (filled(config('pagespeed.api_key'))) {
                $query['key'] = config('pagespeed.api_key');
            }
            // No automatic HTTP retries: the durable measurement owns the request budget.
            $response = Http::acceptJson()->connectTimeout(10)->timeout(min(60, max(1, (int) config('pagespeed.timeout_seconds'))))
                ->withOptions(['allow_redirects' => false, 'sink' => $sink])->get(self::ENDPOINT, $query);
            if (strlen($response->body()) > $limit) {
                throw new PageSpeedException('response_too_large', 'The PageSpeed response exceeded the size limit.');
            }
            if (! $response->successful()) {
                $status = $response->status();
                $retryAfter = $response->header('Retry-After');
                $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time());
                $reasons = data_get($response->json(), 'error.errors.*.reason', []);
                $quota = $status === 403 && is_array($reasons) && array_intersect($reasons, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded']) !== [];
                if ($quota) {
                    throw new PageSpeedException('quota_exceeded', 'The PageSpeed API quota was exceeded.', true, $status,
                        in_array('dailyLimitExceeded', $reasons, true) ? 86400 : min(86400, max(60, $seconds)));
                }
                throw new PageSpeedException('http_'.$status, 'PageSpeed returned HTTP '.$status.'.', in_array($status, [408, 425, 429], true) || $status >= 500, $status, min(86400, max(60, $seconds)));
            }
            $json = $response->json();
            if (! is_array($json)) {
                throw new PageSpeedException('invalid_response', 'PageSpeed returned an invalid response.', true);
            }

            return $json;
        } catch (PageSpeedException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Exception messages may contain the query-string API key. Never persist them.
            throw new PageSpeedException('connection_failed', 'The PageSpeed request could not be completed.', true);
        } finally {
            $sink->close();
        }
    }
}
