<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

/**
 * DNS resolver contract — resolve hostnames through different paths.
 *
 * Implementations handle local system DNS, remote DNS via SOCKS5,
 * or DNS through the proxy itself (SOCKS5h semantics).
 */
interface DnsResolverContract
{
    /**
     * Resolve hostname to IP addresses through the configured DNS mode.
     *
     * @return list<string> resolved IP addresses.
     *
     * @throws DnsResolutionException on failure.
     */
    public function resolve(string $hostname): array;

    public function mode(): SocksDnsMode;
}
