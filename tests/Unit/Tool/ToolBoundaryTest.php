<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Tool\CredentialChannel;
use BAGArt\ProxyOperations\Tool\CredentialDeliveryMode;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
use BAGArt\ProxyOperations\Tool\ProbeSpec;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Tool\ToolId;

function probeExecutionContext(): ProbeExecutionContext
{
    return new ProbeExecutionContext(
        host: '192.0.2.10',
        port: 1080,
        credentials: new StdinChannel,
        spec: new ProbeSpec(ProbeType::DnsResolution, 'https://judge.example/check'),
        timeoutMs: 5000,
        maxOutputBytes: 65536,
    );
}

it('rejects invalid tool id slugs', function (string $value): void {
    new ToolId($value);
})->with([
    [''],
    ['UPPERCASE'],
    ['-leading-dash'],
    ['trailing-'],
    ['double--dash'],
    ['has space'],
    [str_repeat('a', 65)],
])->throws(InvalidArgumentException::class);

it('accepts valid tool id slugs', function (): void {
    expect(new ToolId('socks-checker.v2')->value)->toBe('socks-checker.v2');
});

it('rejects an empty endpoint host', function (): void {
    new ProbeExecutionContext(
        host: '',
        port: 1080,
        credentials: new StdinChannel,
        spec: new ProbeSpec(ProbeType::HttpLiveness, 'target'),
        timeoutMs: 1000,
        maxOutputBytes: 1024,
    );
})->throws(InvalidArgumentException::class, 'host must not be empty');

it('rejects an out-of-range endpoint port', function (int $port): void {
    new ProbeExecutionContext(
        host: 'proxy.example',
        port: $port,
        credentials: new StdinChannel,
        spec: new ProbeSpec(ProbeType::HttpLiveness, 'target'),
        timeoutMs: 1000,
        maxOutputBytes: 1024,
    );
})->with([0, 70000, -5])->throws(InvalidArgumentException::class, 'port must be within');

it('rejects non-positive per-probe limits', function (int $timeoutMs, int $maxOutputBytes): void {
    new ProbeExecutionContext(
        host: 'proxy.example',
        port: 1080,
        credentials: new FileDescriptorChannel(3),
        spec: new ProbeSpec(ProbeType::HttpLiveness, 'target'),
        timeoutMs: $timeoutMs,
        maxOutputBytes: $maxOutputBytes,
    );
})->with([
    [0, 1024],
    [5000, 0],
])->throws(InvalidArgumentException::class);

it('carries the credential channel, never a credential string', function (): void {
    $context = probeExecutionContext();

    expect($context->credentials)->toBeInstanceOf(CredentialChannel::class)
        ->and($context->credentials->mode())->toBe(CredentialDeliveryMode::Stdin);
});

it('returns observations and timings on success', function (): void {
    $result = ProbeToolResult::ok(['http_status' => 200], ['connect_ms' => 12.5]);

    expect($result->ok)->toBeTrue()
        ->and($result->observations)->toBe(['http_status' => 200])
        ->and($result->timingsMs)->toBe(['connect_ms' => 12.5])
        ->and($result->failure)->toBeNull();
});

it('returns only an execution failure on tool-level faults', function (): void {
    $failure = (new FailureTaxonomy)->failure(FailureCode::ToolTimeout, ['tool' => 'socks-checker']);

    $result = ProbeToolResult::failed($failure, ['elapsed_ms' => 30000.0]);

    expect($result->ok)->toBeFalse()
        ->and($result->observations)->toBe([])
        ->and($result->failure)->toBe($failure);
});

it('defines all eight governor limits as positive values', function (): void {
    $spec = new ResourceGovernorSpec(
        maxConcurrentProbes: 100,
        maxProcesses: 64,
        maxMemoryBytes: 512 * 1024 * 1024,
        maxCpuPercent: 80,
        maxExecutionTimeSeconds: 30,
        maxOutputBytes: 1024 * 1024,
        maxStdinBytes: 4096,
        maxFileDescriptors: 256,
    );

    foreach (get_object_vars($spec) as $limit) {
        expect($limit)->toBeGreaterThan(0);
    }
});

it('rejects any non-positive governor limit', function (): void {
    new ResourceGovernorSpec(
        maxConcurrentProbes: 100,
        maxProcesses: 64,
        maxMemoryBytes: 512 * 1024 * 1024,
        maxCpuPercent: 0,
        maxExecutionTimeSeconds: 30,
        maxOutputBytes: 1024 * 1024,
        maxStdinBytes: 4096,
        maxFileDescriptors: 256,
    );
})->throws(InvalidArgumentException::class, 'maxCpuPercent must be positive');
