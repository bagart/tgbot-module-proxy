<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

/**
 * Opaque handle for an active UDP relay through a SOCKS5 proxy.
 *
 * Carries the control TCP connection and the UDP socket bound
 * to the relay address returned by UDP ASSOCIATE.
 */
final readonly class UdpRelayHandle
{
    public function __construct(
        public readonly string $relayHost,
        public readonly int $relayPort,
        /** @var resource */
        public readonly mixed $controlSocket,
        /** @var resource */
        public readonly mixed $udpSocket,
    ) {}
}
