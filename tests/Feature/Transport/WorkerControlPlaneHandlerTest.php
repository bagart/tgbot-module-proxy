<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\ControlPlaneRoute;
use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Transport\TransportToolManifestProvider;
use BAGArt\ProxyOperations\Transport\WorkerControlPlaneHandler;

function buildHandler(
    ?ResourceGovernor $governor = null,
): WorkerControlPlaneHandler {
    $g = $governor ?? new ResourceGovernor(new ResourceGovernorSpec(
        maxConcurrentProbes: 5,
        maxProcesses: 10,
        maxMemoryBytes: 1024 * 1024,
        maxCpuPercent: 80,
        maxExecutionTimeSeconds: 30,
        maxOutputBytes: 1024,
        maxStdinBytes: 1024,
        maxFileDescriptors: 64,
    ));
    $manifestProvider = new TransportToolManifestProvider;
    $manifests = [];
    foreach ($manifestProvider->manifests() as $manifest) {
        $manifests[$manifest->name->value] = $manifest;
    }

    return new WorkerControlPlaneHandler(
        toolRegistry: new ToolRegistry($manifests),
        governor: $g,
        manifestProvider: $manifestProvider,
    );
}

it('GET /health returns ok', function (): void {
    $handler = buildHandler();
    $result = $handler->handle(ControlPlaneRoute::Health);

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('ok');
});

it('GET /ready returns 200 when governor has capacity', function (): void {
    $handler = buildHandler();
    $result = $handler->handle(ControlPlaneRoute::Ready);

    expect($result)->toHaveKey('status')
        ->and($result['status'])->toBe('ready')
        ->and($result['code'])->toBe(200);
});

it('GET /ready returns 503 when governor is at capacity', function (): void {
    $governor = new ResourceGovernor(new ResourceGovernorSpec(
        maxConcurrentProbes: 1,
        maxProcesses: 1,
        maxMemoryBytes: 1024,
        maxCpuPercent: 80,
        maxExecutionTimeSeconds: 5,
        maxOutputBytes: 1024,
        maxStdinBytes: 1024,
        maxFileDescriptors: 64,
    ));
    $governor->connectionOpened();

    $handler = buildHandler(governor: $governor);
    $result = $handler->handle(ControlPlaneRoute::Ready);

    expect($result['status'])->toBe('not_ready')
        ->and($result['code'])->toBe(503);
});

it('GET /capabilities returns all registered tool manifests', function (): void {
    $handler = buildHandler();
    $result = $handler->handle(ControlPlaneRoute::Capabilities);

    expect($result)->toHaveKeys([
        'http-connect-adapter',
        'socks4-adapter',
        'socks5-adapter',
        'dns-resolver',
        'udp-associate-adapter',
    ]);
});

it('GET /metrics returns governor stats', function (): void {
    $handler = buildHandler();
    $result = $handler->handle(ControlPlaneRoute::Metrics);

    expect($result)->toHaveKeys(['activeConnections', 'totalBytesIn', 'totalBytesOut']);
});
