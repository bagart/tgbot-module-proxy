<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Tool\ToolCapabilities;
use BAGArt\ProxyOperations\Tool\ToolId;
use BAGArt\ProxyOperations\Tool\ToolLimits;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolSecurity;

/**
 * Provides ToolManifest instances for transport adapters (plan §11.39 пп.10–11).
 * Each transport adapter gets a manifest that declares its supported probe types,
 * protocols, and security constraints.
 */
final readonly class TransportToolManifestProvider
{
    /**
     * @return list<ToolManifest>
     */
    public function manifests(): array
    {
        return [
            $this->httpConnectManifest(),
            $this->socks4Manifest(),
            $this->socks5Manifest(),
            $this->dnsResolverManifest(),
            $this->udpAssociateManifest(),
        ];
    }

    private function httpConnectManifest(): ToolManifest
    {
        return new ToolManifest(
            name: new ToolId('http-connect-adapter'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: [ProbeType::HttpLiveness, ProbeType::LatencySeries],
                protocols: ['http', 'https'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 30, maxOutputBytes: 1024 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        );
    }

    private function socks4Manifest(): ToolManifest
    {
        return new ToolManifest(
            name: new ToolId('socks4-adapter'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: [ProbeType::HttpLiveness, ProbeType::LatencySeries],
                protocols: ['socks4', 'socks4a'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 30, maxOutputBytes: 1024 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        );
    }

    private function socks5Manifest(): ToolManifest
    {
        return new ToolManifest(
            name: new ToolId('socks5-adapter'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: [ProbeType::HttpLiveness, ProbeType::LatencySeries, ProbeType::UdpAssociate, ProbeType::DnsResolution],
                protocols: ['socks5', 'socks5h'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 30, maxOutputBytes: 1024 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        );
    }

    private function dnsResolverManifest(): ToolManifest
    {
        return new ToolManifest(
            name: new ToolId('dns-resolver'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: [ProbeType::DnsResolution],
                protocols: ['local', 'remote', 'proxy_dns'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 15, maxOutputBytes: 64 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        );
    }

    private function udpAssociateManifest(): ToolManifest
    {
        return new ToolManifest(
            name: new ToolId('udp-associate-adapter'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: [ProbeType::UdpAssociate],
                protocols: ['socks5', 'socks5h'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 15, maxOutputBytes: 1024 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        );
    }
}
