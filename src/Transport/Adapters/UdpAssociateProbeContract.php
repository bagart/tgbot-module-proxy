<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport\Adapters;

use BAGArt\ProxyOperations\Transport\ProxyConfig;

/**
 * Contract for UDP ASSOCIATE probes through SOCKS5 proxies.
 * Extracted from Socks5UdpAdapter to allow test doubles (the adapter is final).
 */
interface UdpAssociateProbeContract
{
    /**
     * Establish UDP ASSOCIATE and return relay information.
     */
    public function associate(
        ProxyConfig $config,
        string $targetHost,
        int $targetPort,
    ): UdpAssociateResult;
}
