<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

function wireProbeSpec(): ProbeExecutionSpecV1
{
    return new ProbeExecutionSpecV1(
        probeType: ProbeType::HttpLiveness,
        profile: ProbeProfile::Light,
        target: 'http://judge-1.example.test/ping',
        timeoutMs: 5000,
        maxOutputBytes: 65536,
    );
}

it('round-trips through JSON', function (): void {
    expect(ProbeExecutionSpecV1::fromJson(wireProbeSpec()->jsonSerialize()))->toEqual(wireProbeSpec());
});

it('rejects an unknown schemaVersion', function (): void {
    $data = wireProbeSpec()->jsonSerialize();
    $data['schemaVersion'] = 99;

    ProbeExecutionSpecV1::fromJson($data);
})->throws(RuntimeException::class, 'Unsupported ProbeExecutionSpec schemaVersion');

it('requires positive limits', function (): void {
    new ProbeExecutionSpecV1(
        probeType: ProbeType::HttpLiveness,
        profile: ProbeProfile::Light,
        target: 'http://judge-1.example.test/ping',
        timeoutMs: 0,
        maxOutputBytes: 1024,
    );
})->throws(InvalidArgumentException::class, 'timeoutMs');
