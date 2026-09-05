<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Transport\Adapters\UdpAssociateProbeContract;
use BAGArt\ProxyOperations\Transport\CapabilityProbeRunner;
use BAGArt\ProxyOperations\Transport\DnsResolverFactory;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Transport\RunCapabilityProbesCommand;
use BAGArt\ProxyOperations\Transport\TransportAdapterResolver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function createCommand(): RunCapabilityProbesCommand
{
    $udp = Mockery::mock(UdpAssociateProbeContract::class);
    $probeRunner = new CapabilityProbeRunner(
        new TransportAdapterResolver,
        new DnsResolverFactory,
        $udp,
    );
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

    return new RunCapabilityProbesCommand($probeRunner, $governor);
}

it('command has correct name', function (): void {
    $command = createCommand();

    expect($command->getName())->toBe('proxy:probe-capabilities');
});

it('command runs successfully with no options', function (): void {
    $command = createCommand();
    $input = new ArrayInput([]);
    $output = new BufferedOutput;

    $exitCode = $command->run($input, $output);

    expect($exitCode)->toBe(0)
        ->and($output->fetch())->toContain('Running capability probes');
});

it('command accepts --endpoint-id option', function (): void {
    $command = createCommand();
    $input = new ArrayInput(['--endpoint-id' => '123']);
    $output = new BufferedOutput;

    $exitCode = $command->run($input, $output);

    expect($exitCode)->toBe(0)
        ->and($output->fetch())->toContain('endpoint ID: 123');
});

it('command accepts --protocol option', function (): void {
    $command = createCommand();
    $input = new ArrayInput(['--protocol' => 'socks5']);
    $output = new BufferedOutput;

    $exitCode = $command->run($input, $output);

    expect($exitCode)->toBe(0)
        ->and($output->fetch())->toContain('protocol: socks5');
});
