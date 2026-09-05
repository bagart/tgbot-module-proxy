<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use InvalidArgumentException;

/**
 * Validates transport-level proxy configuration against protocol rules
 * (plan §11.4–11.5). Throws InvalidArgumentException with descriptive codes
 * for each violation, never leaking credential content.
 */
final readonly class ProxyConfigValidator
{
    /**
     * Protocols that accept StdinChannel credentials.
     *
     * @var list<ProxyProtocol>
     */
    private const array STDIN_CHANNEL_PROTOCOLS = [
        ProxyProtocol::Socks4,
        ProxyProtocol::Socks4a,
        ProxyProtocol::Socks5,
        ProxyProtocol::Socks5h,
    ];

    public function validate(ProxyConfig $config): void
    {
        $this->assertTransportOptionsClass($config);
        $this->assertCredentialChannelCompatibility($config);
        $this->assertSocksOptionsConsistency($config);
        $this->assertUdpAssociateCompatibility($config);
    }

    /**
     * Assert that transportOptions is the expected class for the scheme.
     */
    private function assertTransportOptionsClass(ProxyConfig $config): void
    {
        $expectedClass = match ($config->scheme) {
            ProxyProtocol::Socks5, ProxyProtocol::Socks5h => SocksOptions::class,
            ProxyProtocol::Socks4, ProxyProtocol::Socks4a,
            ProxyProtocol::Http, ProxyProtocol::Https => HttpConnectOptions::class,
            ProxyProtocol::Mtproto => MtprotoOptions::class,
        };

        if (! $config->transportOptions instanceof $expectedClass) {
            throw new InvalidArgumentException(
                "Protocol '{$config->scheme->value}' requires {$expectedClass}, "
                .get_class($config->transportOptions).' given.',
            );
        }
    }

    /**
     * Assert CredentialChannel type matches protocol expectations:
     * StdinChannel for SOCKS4/5, FileDescriptorChannel accepted everywhere.
     */
    private function assertCredentialChannelCompatibility(ProxyConfig $config): void
    {
        if ($config->credential === null) {
            return;
        }

        $channel = $config->credential->channel;

        if ($channel instanceof StdinChannel && ! in_array($config->scheme, self::STDIN_CHANNEL_PROTOCOLS, true)) {
            throw new InvalidArgumentException(
                "StdinChannel is not supported for protocol '{$config->scheme->value}'.",
            );
        }

        if (! $channel instanceof StdinChannel && ! $channel instanceof FileDescriptorChannel) {
            throw new InvalidArgumentException(
                'Unsupported CredentialChannel class: '.get_class($channel).'.',
            );
        }
    }

    /**
     * Assert SocksOptions.dnsMode is compatible with scheme:
     * ProxyDns only for Socks5h; Remote only for Socks5/Socks5h.
     */
    private function assertSocksOptionsConsistency(ProxyConfig $config): void
    {
        if (! $config->transportOptions instanceof SocksOptions) {
            return;
        }

        $dnsMode = $config->transportOptions->dnsMode;

        if ($dnsMode === SocksDnsMode::ProxyDns && $config->scheme !== ProxyProtocol::Socks5h) {
            throw new InvalidArgumentException(
                "ProxyDns mode is only supported for Socks5h, '{$config->scheme->value}' given.",
            );
        }

        if ($dnsMode === SocksDnsMode::Remote
            && $config->scheme !== ProxyProtocol::Socks5
            && $config->scheme !== ProxyProtocol::Socks5h
        ) {
            throw new InvalidArgumentException(
                "Remote DNS mode is only supported for Socks5/Socks5h, '{$config->scheme->value}' given.",
            );
        }
    }

    /**
     * Assert enableUdpAssociate only for Socks5/Socks5h.
     */
    private function assertUdpAssociateCompatibility(ProxyConfig $config): void
    {
        if (! $config->transportOptions instanceof SocksOptions) {
            return;
        }

        if ($config->transportOptions->enableUdpAssociate
            && $config->scheme !== ProxyProtocol::Socks5
            && $config->scheme !== ProxyProtocol::Socks5h
        ) {
            throw new InvalidArgumentException(
                "UDP ASSOCIATE is only supported for Socks5/Socks5h, '{$config->scheme->value}' given.",
            );
        }
    }
}
