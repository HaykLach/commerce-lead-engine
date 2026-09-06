<?php

declare(strict_types=1);

namespace App\Services\Contacts;

class ContactEvidence
{
    public static function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));

        return strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    // Validation for displaying evidence links only; this service does not fetch URLs.
    public static function url(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)) {
            return null;
        }
        $parts = parse_url($value);
        if (! filter_var($value, FILTER_VALIDATE_URL) || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return explode('#', $value, 2)[0];
    }

    public static function host(string $value): string
    {
        return preg_replace('/^www\./', '', strtolower(rtrim($value, '.')));
    }

    public static function sameDomain(string $email, string $domain): bool
    {
        return self::host(substr(strrchr($email, '@'), 1)) === self::host($domain);
    }
}
