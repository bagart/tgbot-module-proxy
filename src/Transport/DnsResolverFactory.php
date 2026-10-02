<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Transport\Adapters\Socks5Adapter;

/**
 * Factory for creating the appropriate DNS resolver based on ProxyConfig.
 */
final readonly class DnsResolverFactory
{
    public function create(ProxyConfig $config): DnsResolverContract
    {
        if ($config->transportOptions instanceof SocksOptions) {
            return match ($config->transportOptions->dnsMode) {
                SocksDnsMode::ProxyDns => new ProxyDnsResolver(
                    new Socks5Adapter(),
                    $config,
                ),
                SocksDnsMode::Remote => new RemoteDnsResolver(
                    new Socks5Adapter(),
                    $config,
                ),
                SocksDnsMode::Local => new LocalDnsResolver(),
            };
        }

        return new LocalDnsResolver();
    }
}
