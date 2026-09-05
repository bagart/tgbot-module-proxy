<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Identity;

/**
 * Single source of truth for what each proxy protocol supports (plan §11.4).
 * Every probe type must consult this matrix instead of hard-coding protocol
 * assumptions.
 */
final class ProtocolCapabilityMatrix
{
    /**
     * Transports each protocol can operate over.
     *
     * @var array<non-empty-string, list<TransportKind>>
     */
    private const array TRANSPORTS = [
        ProxyProtocol::Http->name => [TransportKind::TcpDirect, TransportKind::TcpViaProxy],
        ProxyProtocol::Https->name => [TransportKind::TcpDirect, TransportKind::TcpViaProxy],
        ProxyProtocol::Socks4->name => [TransportKind::TcpDirect, TransportKind::TcpViaProxy],
        ProxyProtocol::Socks4a->name => [TransportKind::TcpDirect, TransportKind::TcpViaProxy],
        ProxyProtocol::Socks5->name => [
            TransportKind::TcpDirect,
            TransportKind::TcpViaProxy,
            TransportKind::UdpAssociate,
            TransportKind::DnsModes,
        ],
        ProxyProtocol::Socks5h->name => [
            TransportKind::TcpDirect,
            TransportKind::TcpViaProxy,
            TransportKind::UdpAssociate,
            TransportKind::DnsModes,
        ],
        ProxyProtocol::Mtproto->name => [TransportKind::TcpDirect],
    ];

    /**
     * Application capabilities each protocol provides (rows of plan §11.4).
     *
     * @var array<non-empty-string, list<ApplicationCapability>>
     */
    private const array CAPABILITIES = [
        ProxyProtocol::Http->name => [
            ApplicationCapability::HttpTargets,
            ApplicationCapability::HttpsViaConnect,
            ApplicationCapability::TelegramConnectivity,
        ],
        ProxyProtocol::Https->name => [
            ApplicationCapability::HttpTargets,
            ApplicationCapability::HttpsViaConnect,
            ApplicationCapability::TelegramConnectivity,
        ],
        ProxyProtocol::Socks4->name => [
            ApplicationCapability::HttpTargets,
            ApplicationCapability::HttpsViaConnect,
            ApplicationCapability::TelegramConnectivity,
        ],
        ProxyProtocol::Socks4a->name => [
            ApplicationCapability::HttpTargets,
            ApplicationCapability::HttpsViaConnect,
            ApplicationCapability::TelegramConnectivity,
        ],
        ProxyProtocol::Socks5->name => [
            ApplicationCapability::HttpTargets,
            ApplicationCapability::HttpsViaConnect,
            ApplicationCapability::UdpAssociate,
            ApplicationCapability::RemoteDns,
            ApplicationCapability::TelegramConnectivity,
        ],
        ProxyProtocol::Socks5h->name => [
            ApplicationCapability::HttpTargets,
            ApplicationCapability::HttpsViaConnect,
            ApplicationCapability::UdpAssociate,
            ApplicationCapability::RemoteDns,
            ApplicationCapability::TelegramConnectivity,
        ],
        ProxyProtocol::Mtproto->name => [ApplicationCapability::TelegramConnectivity],
    ];

    /**
     * @return list<TransportKind>
     */
    public function transportsFor(ProxyProtocol $protocol): array
    {
        return self::TRANSPORTS[$protocol->name];
    }

    public function supportsTransport(ProxyProtocol $protocol, TransportKind $transport): bool
    {
        return in_array($transport, $this->transportsFor($protocol), true);
    }

    public function supports(ProxyProtocol $protocol, ApplicationCapability $capability): bool
    {
        return in_array($capability, self::CAPABILITIES[$protocol->name], true);
    }
}
