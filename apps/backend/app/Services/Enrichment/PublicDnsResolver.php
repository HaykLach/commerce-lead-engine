<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Symfony\Component\HttpFoundation\IpUtils;

class PublicDnsResolver
{
    public function resolve(string $host): string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->lookup($host);
        if ($addresses === []) {
            throw new FetchException('DNS lookup returned no addresses.', true);
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new FetchException('The hostname resolves to a non-public address.');
            }
        }

        return $addresses[0];
    }

    protected function lookup(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_unique(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: []))));
    }

    public function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            return IpUtils::checkIp($ip, '2000::/3') && ! IpUtils::checkIp($ip, ['2001::/23', '2001:db8::/32', '2002::/16']);
        }

        return ! IpUtils::checkIp($ip, ['0.0.0.0/8', '100.64.0.0/10', '169.254.0.0/16', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']);
    }
}
