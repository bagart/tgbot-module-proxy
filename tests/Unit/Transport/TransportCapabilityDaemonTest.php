<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKWarmableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;
use BAGArt\AsyncKernel\Enum\ShutdownPhase;
use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Transport\Adapters\UdpAssociateProbeContract;
use BAGArt\ProxyOperations\Transport\CapabilityProbeRunner;
use BAGArt\ProxyOperations\Transport\DnsResolverFactory;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Transport\TransportAdapterResolver;
use BAGArt\ProxyOperations\Transport\TransportCapabilityDaemon;

function createDaemon(): TransportCapabilityDaemon
{
    $adapterResolver = new TransportAdapterResolver;
    $dnsFactory = new DnsResolverFactory;
    $udp = Mockery::mock(UdpAssociateProbeContract::class);

    $probeRunner = new CapabilityProbeRunner($adapterResolver, $dnsFactory, $udp);
    $governor = new ResourceGovernor(new ResourceGovernorSpec(
        maxConcurrentProbes: 5,
        maxProcesses: 10,
        maxMemoryBytes: 1024 * 1024,
        maxCpuPercent: 80,
        maxExecutionTimeSeconds: 30,
        maxOutputBytes: 1024,
        maxStdinBytes: 1024,
        maxFileDescriptors: 64,
    ));

    return new TransportCapabilityDaemon($probeRunner, $governor);
}

it('implements ASKDaemonContract', function (): void {
    $daemon = createDaemon();

    expect($daemon)->toBeInstanceOf(ASKDaemonContract::class);
});

it('implements WithASKTickableContract', function (): void {
    $daemon = createDaemon();

    expect($daemon)->toBeInstanceOf(WithASKTickableContract::class);
});

it('implements ASKWarmableContract', function (): void {
    $daemon = createDaemon();

    expect($daemon)->toBeInstanceOf(ASKWarmableContract::class);
});

it('warm() sets warmed state', function (): void {
    $daemon = createDaemon();

    expect($daemon->isWarmed())->toBeFalse();

    $daemon->warm();

    expect($daemon->isWarmed())->toBeTrue();
});

it('startup does not throw', function (): void {
    $daemon = createDaemon();
    $daemon->startup();

    expect(true)->toBeTrue();
});

it('shutdown flushes governor and returns true', function (): void {
    $daemon = createDaemon();
    $daemon->warm();

    $context = new ASKShutdownContext(
        phase: ShutdownPhase::DRAINING,
        forced: false,
        deadline: microtime(true) + 30,
    );

    $result = $daemon->shutdown($context);

    expect($result)->toBeTrue()
        ->and($daemon->isShuttingDown())->toBeTrue();
});

it('tickable returns array', function (): void {
    $daemon = createDaemon();

    expect($daemon->tickable())->toBeArray();
});

it('name returns the daemon name', function (): void {
    $daemon = createDaemon();

    expect($daemon->name())->toBe('TransportCapabilityDaemon');
});

it('onError does not throw', function (): void {
    $daemon = createDaemon();
    $daemon->onError(new RuntimeException('test'));

    expect(true)->toBeTrue();
});
