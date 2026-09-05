<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

/**
 * Network transport used to reach targets through (or around) a proxy.
 */
enum TransportKind: string
{
    case TcpDirect = 'tcp_direct';
    case TcpViaProxy = 'tcp_via_proxy';
    case UdpAssociate = 'udp_associate';
    case DnsModes = 'dns_modes';
}
