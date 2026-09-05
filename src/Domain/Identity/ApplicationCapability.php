<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

/**
 * Application-level capabilities a proxy protocol can offer (plan §11.4).
 */
enum ApplicationCapability: string
{
    case HttpTargets = 'http_targets';
    case HttpsViaConnect = 'https_via_connect';
    case UdpAssociate = 'udp_associate';
    case RemoteDns = 'remote_dns';
    case TelegramConnectivity = 'telegram_connectivity';
}
