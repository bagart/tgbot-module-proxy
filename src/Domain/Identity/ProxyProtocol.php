<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

/**
 * Application-layer proxy protocol.
 *
 * MTProto is an application protocol over plain TCP, not a transport of its
 * own (plan §11.35 item 8 / INV-008) — hence it lives here alongside HTTP and
 * SOCKS rather than in TransportKind.
 *
 * @see https://core.telegram.org/mtproto/mtproto-transports#mtproto-proxy
 */
enum ProxyProtocol: string
{
    case Http = 'http';
    case Https = 'https';
    case Socks4 = 'socks4';
    case Socks4a = 'socks4a';
    case Socks5 = 'socks5';
    case Socks5h = 'socks5h';
    case Mtproto = 'mtproto';

    public function defaultPort(): int
    {
        return match ($this) {
            self::Http => 80,
            self::Https, self::Mtproto => 443,
            self::Socks4, self::Socks4a, self::Socks5, self::Socks5h => 1080,
        };
    }
}
