<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

/**
 * Builds ProxyConfig from wire contracts (AuditTaskV1) or for direct TCP
 * connections (judge fetches). Validates the result through ProxyConfigValidator.
 */
final readonly class ProxyConfigFactory
{
    public function __construct(
        private readonly ProxyConfigValidator $validator,
    ) {
    }

    /**
     * Build ProxyConfig from a wire AuditTaskV1 for a specific probe.
     * Extracts host/port/scheme from AuditTaskV1.accessRef (via
     * AccessIdentity → EndpointIdentity → ProxyProtocol).
     * Builds CredentialChannel from sealed credential payload.
     * Selects TransportOptions from protocol defaults.
     */
    public function fromAuditTask(
        AuditTaskV1 $task,
        ProbeExecutionSpecV1 $probe,
    ): ProxyConfig {
        $endpoint = $task->accessRef->endpoint;
        $scheme = $endpoint->protocol;
        $credential = $this->buildCredentialRef($task);

        $config = new ProxyConfig(
            scheme: $scheme,
            host: $endpoint->host,
            port: $endpoint->port,
            credential: $credential,
            tls: new TlsOptions(),
            transportOptions: $this->defaultTransportOptions($scheme),
        );

        $this->validator->validate($config);

        return $config;
    }

    /**
     * Build ProxyConfig for a direct TCP connection (e.g., judge fetch).
     * No proxy, no credential.
     */
    public function direct(string $host, int $port): ProxyConfig
    {
        $config = new ProxyConfig(
            scheme: ProxyProtocol::Http,
            host: $host,
            port: $port,
            credential: null,
            tls: new TlsOptions(),
            transportOptions: new HttpConnectOptions(),
        );

        $this->validator->validate($config);

        return $config;
    }

    /**
     * Build ProxyCredentialRef from task credential payload.
     */
    private function buildCredentialRef(AuditTaskV1 $task): ?ProxyCredentialRef
    {
        if ($task->sealedCredential !== null) {
            return new ProxyCredentialRef(
                username: 'proxy',
                channel: new StdinChannel(),
            );
        }

        if ($task->credentialReference !== null) {
            return new ProxyCredentialRef(
                username: $this->extractUsernameFromHandle($task->credentialReference),
                channel: new FileDescriptorChannel(fileDescriptor: 3),
            );
        }

        return null;
    }

    /**
     * Extract a username hint from the credential reference handle.
     */
    private function extractUsernameFromHandle(CredentialReference $reference): string
    {
        $parts = explode(':', $reference->handle);

        return $parts[0] !== '' ? $parts[0] : 'proxy';
    }

    /**
     * Default TransportOptions for a given protocol.
     */
    private function defaultTransportOptions(ProxyProtocol $scheme): TransportOptions
    {
        return match ($scheme) {
            ProxyProtocol::Socks5, ProxyProtocol::Socks5h => new SocksOptions(),
            ProxyProtocol::Socks4, ProxyProtocol::Socks4a,
            ProxyProtocol::Http, ProxyProtocol::Https => new HttpConnectOptions(),
            ProxyProtocol::Mtproto => new MtprotoOptions(),
        };
    }
}
