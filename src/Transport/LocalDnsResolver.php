<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

/**
 * Local system DNS resolver — uses PHP native DNS resolution.
 * No proxy involvement.
 */
final class LocalDnsResolver implements DnsResolverContract
{
    public function resolve(string $hostname): array
    {
        $ips = @dns_get_record($hostname, DNS_A);

        if ($ips === false || $ips === []) {
            throw new DnsResolutionException(
                "Local DNS resolution failed for host: {$hostname}.",
            );
        }

        return array_values(array_column($ips, 'ip'));
    }

    public function mode(): SocksDnsMode
    {
        return SocksDnsMode::Local;
    }
}
