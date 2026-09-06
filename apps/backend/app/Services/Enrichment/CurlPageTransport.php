<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

class CurlPageTransport
{
    public function get(string $url, string $ip, float $timeout): array
    {
        if (! extension_loaded('curl')) {
            throw new FetchException('The PHP cURL extension is required.');
        }
        $body = '';
        $headers = [];
        $bytes = 0;
        $tooLarge = false;
        $maxBytes = min(2_000_000, max(1024, (int) config('enrichment.max_html_bytes')));
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'https:') ? 443 : 80);
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_PROXY => '',
            CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($ip, ':') ? '['.$ip.']' : $ip)],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT_MS => max(1, (int) ($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => min(3000, max(1, (int) ($timeout * 1000))),
            CURLOPT_USERAGENT => config('enrichment.user_agent'),
            CURLOPT_HTTPHEADER => ['Accept: text/html, application/xhtml+xml'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers, &$bytes, &$tooLarge): int {
                $bytes += strlen($line);
                if ($bytes > 32768) {
                    $tooLarge = true;

                    return 0;
                }
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($key))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        try {
            $success = curl_exec($handle);
            if ($tooLarge) {
                throw new FetchException('The response exceeded the audit size limit.');
            }
            if ($success === false) {
                throw new FetchException('HTTP transport failed (cURL '.curl_errno($handle).').', true);
            }

            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
        } finally {
            curl_close($handle);
        }
    }
}
