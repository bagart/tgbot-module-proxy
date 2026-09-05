<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use BAGArt\ProxyOperations\Transport\ProxyConfig;

/**
 * Transport adapter that performs actual network connections through proxy
 * protocols (plan §11.39 п.3). Each adapter handles one protocol family:
 * HTTP CONNECT, SOCKS4/4a, SOCKS5/5h, or direct TCP.
 *
 * Adapters are stateless services — no I/O in constructors (lazy connect);
 * connect() establishes the tunnel and returns a stream handle.
 */
interface TransportAdapterContract
{
    /**
     * Establish a connection to $targetHost:$targetPort through the proxy
     * described by $config. Returns a resource stream handle.
     *
     * @return resource|false Stream resource on success, false on connection failure.
     *
     * @throws TransportConnectionException on connection failure.
     * @throws TransportAuthException if proxy authentication fails.
     */
    public function connect(
        ProxyConfig $config,
        string $targetHost,
        int $targetPort,
    ): mixed;

    /**
     * Close the connection and release resources. Idempotent.
     */
    public function close(): void;
}
