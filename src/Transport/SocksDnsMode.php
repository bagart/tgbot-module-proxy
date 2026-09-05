<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

/**
 * DNS resolution semantics within a SOCKS5/5h tunnel (plan §11.5).
 */
enum SocksDnsMode: string
{
    case Local = 'local';
    case Remote = 'remote';
    case ProxyDns = 'proxy_dns';
}
