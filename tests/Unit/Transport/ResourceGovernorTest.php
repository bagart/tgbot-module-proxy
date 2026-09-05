<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;

function defaultGovernorSpec(): ResourceGovernorSpec
{
    return new ResourceGovernorSpec(
        maxConcurrentProbes: 5,
        maxProcesses: 10,
        maxMemoryBytes: 1024 * 1024 * 100,
        maxCpuPercent: 80,
        maxExecutionTimeSeconds: 30,
        maxOutputBytes: 1024,
        maxStdinBytes: 1024,
        maxFileDescriptors: 64,
    );
}

function defaultGovernor(): ResourceGovernor
{
    return new ResourceGovernor(defaultGovernorSpec());
}

it('allows opening a connection when below limit', function (): void {
    $governor = defaultGovernor();

    expect($governor->canOpenConnection())->toBeTrue();
});

it('disallows opening a connection at limit', function (): void {
    $governor = defaultGovernor();

    for ($i = 0; $i < 5; $i++) {
        $governor->connectionOpened();
    }

    expect($governor->canOpenConnection())->toBeFalse();
});

it('tracks active connections correctly', function (): void {
    $governor = defaultGovernor();

    $governor->connectionOpened();
    $governor->connectionOpened();
    expect($governor->activeConnections())->toBe(2);

    $governor->connectionClosed();
    expect($governor->activeConnections())->toBe(1);
});

it('does not go below zero on connectionClosed', function (): void {
    $governor = defaultGovernor();

    $governor->connectionClosed();
    expect($governor->activeConnections())->toBe(0);
});

it('truncates data exceeding maxOutputBytes', function (): void {
    $governor = defaultGovernor();
    $data = str_repeat('x', 2048);

    $result = $governor->enforceOutputLimit($data);

    expect($result->truncated)->toBeTrue()
        ->and($result->originalSize)->toBe(2048)
        ->and(strlen($result->data))->toBe(1024);
});

it('does not truncate data within maxOutputBytes', function (): void {
    $governor = defaultGovernor();
    $data = str_repeat('x', 512);

    $result = $governor->enforceOutputLimit($data);

    expect($result->truncated)->toBeFalse()
        ->and($result->data)->toBe($data)
        ->and($result->originalSize)->toBe(512);
});

it('enforceTimeout returns true when within limit', function (): void {
    $governor = defaultGovernor();

    expect($governor->enforceTimeout(5000.0))->toBeTrue();
});

it('enforceTimeout returns false when exceeding limit', function (): void {
    $governor = defaultGovernor();

    expect($governor->enforceTimeout(35000.0))->toBeFalse();
});

it('flush resets all counters', function (): void {
    $governor = defaultGovernor();

    $governor->connectionOpened();
    $governor->connectionOpened();
    $governor->addBytesIn(100);
    $governor->addBytesOut(200);

    $governor->flush();

    expect($governor->activeConnections())->toBe(0)
        ->and($governor->totalBytesIn())->toBe(0)
        ->and($governor->totalBytesOut())->toBe(0);
});

it('tracks bytes in and out', function (): void {
    $governor = defaultGovernor();

    $governor->addBytesIn(100);
    $governor->addBytesOut(200);

    expect($governor->totalBytesIn())->toBe(100)
        ->and($governor->totalBytesOut())->toBe(200);
});
