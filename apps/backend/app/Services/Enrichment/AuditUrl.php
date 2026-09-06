<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Services\Contacts\ContactEvidence;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

class AuditUrl
{
    public static function normalize(string $url, ?string $base = null): ?string
    {
        try {
            if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
                return null;
            }
            $uri = new Uri($url);
            if ($base !== null) {
                $uri = UriResolver::resolve(new Uri($base), $uri);
            }
            if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getUserInfo() !== ''
                || ! preg_match('/^[a-z0-9](?:[a-z0-9.\-]*[a-z0-9])?$/i', $uri->getHost())
                || ! in_array($uri->getPort(), [null, 80, 443], true)) {
                return null;
            }
            if (! filter_var($uri->getHost(), FILTER_VALIDATE_IP)
                && (! str_contains($uri->getHost(), '.') || ! preg_match('/[a-z]/i', $uri->getHost())
                    || preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $uri->getHost()))) {
                return null;
            }
            $value = (string) $uri->withFragment('')->withPath($uri->getPath() ?: '/');

            return ContactEvidence::url($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function sameSite(string $url, string $domain): bool
    {
        return ContactEvidence::host((string) parse_url($url, PHP_URL_HOST)) === ContactEvidence::host($domain);
    }
}
