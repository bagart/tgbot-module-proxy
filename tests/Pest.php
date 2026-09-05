<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Tool\ToolCapabilities;
use BAGArt\ProxyOperations\Tool\ToolId;
use BAGArt\ProxyOperations\Tool\ToolLimits;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolSecurity;

function toolId(): ToolId
{
    return new ToolId('socks-checker');
}

function toolCapabilities(): ToolCapabilities
{
    return new ToolCapabilities(
        probeTypes: [ProbeType::HttpLiveness, ProbeType::DnsResolution],
        protocols: ['socks5'],
        inputSchemaVersion: 1,
        outputSchemaVersion: 1,
    );
}

function toolManifest(): ToolManifest
{
    return new ToolManifest(
        name: toolId(),
        version: '2.4.1',
        apiVersion: 1,
        capabilities: toolCapabilities(),
        limits: new ToolLimits(maxExecutionTimeSeconds: 30, maxOutputBytes: 1024 * 1024),
        security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
    );
}
