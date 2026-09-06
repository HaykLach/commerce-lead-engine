<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

class PublicPageFetcher
{
    public function __construct(private readonly PublicDnsResolver $dns, private readonly CurlPageTransport $transport) {}

    public function fetch(string $url, string $domain): array
    {
        $deadline = microtime(true) + min(8, max(1, (int) config('enrichment.page_timeout_seconds')));
        $visited = [];
        for ($redirect = 0; $redirect <= min(3, (int) config('enrichment.max_redirects')); $redirect++) {
            $url = AuditUrl::normalize($url);
            if ($url === null || ! AuditUrl::sameSite($url, $domain)) {
                throw new FetchException('Invalid URL or redirect outside the audited domain.');
            }
            if (isset($visited[$url])) {
                throw new FetchException('Redirect loop detected.');
            }
            $visited[$url] = true;
            $ip = $this->dns->resolve(parse_url($url, PHP_URL_HOST));
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new FetchException('The page request timed out.', true);
            }
            $response = $this->transport->get($url, $ip, $remaining);
            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                $next = AuditUrl::normalize($response['headers']['location'] ?? '', $url);
                if ($next === null || (str_starts_with($url, 'https:') && str_starts_with($next, 'http:'))) {
                    throw new FetchException('Invalid or insecure redirect.');
                }
                $url = $next;

                continue;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new FetchException('HTTP '.$response['status'], in_array($response['status'], [408, 425, 429], true) || $response['status'] >= 500, $response['status']);
            }
            $type = strtolower(trim(explode(';', $response['headers']['content-type'] ?? '')[0]));
            if (! in_array($type, ['text/html', 'application/xhtml+xml'], true)) {
                throw new FetchException('The response is not an HTML page.', false, $response['status']);
            }

            return ['url' => $url, 'status' => $response['status'], 'html' => $response['body']];
        }
        throw new FetchException('Too many redirects.');
    }
}
