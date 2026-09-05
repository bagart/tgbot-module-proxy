<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Transport\Adapters\Socks5Adapter;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;

/**
 * DNS via SOCKS5 remote resolution — opens a SOCKS5 connection with
 * atyp=0x03 (domain) and target port 53. The SOCKS5 proxy resolves
 * the domain and connects to the DNS server.
 */
final class RemoteDnsResolver implements DnsResolverContract
{
    public function __construct(
        private readonly Socks5Adapter $socks5Adapter,
        private readonly ProxyConfig $proxyConfig,
    ) {}

    public function resolve(string $hostname): array
    {
        try {
            $stream = $this->socks5Adapter->connect($this->proxyConfig, $hostname, 53);

            $remoteAddr = stream_socket_get_name($stream, false);

            $this->socks5Adapter->close();

            if ($remoteAddr !== false) {
                $parts = explode(':', $remoteAddr);
                $ip = $parts[0];

                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return [$ip];
                }
            }
        } catch (TransportConnectionException $e) {
            throw new DnsResolutionException(
                "Remote DNS resolution failed for {$hostname}: {$e->getMessage()}",
                previous: $e,
            );
        }

        return [$hostname];
    }

    public function mode(): SocksDnsMode
    {
        return SocksDnsMode::Remote;
    }
}
