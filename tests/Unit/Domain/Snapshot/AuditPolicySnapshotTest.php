<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Snapshot\AuditPolicySnapshot;

function makePolicySource(): array
{
    return [
        'probeProfileMapping' => ['manual' => ProbeProfile::Deep, 'feed' => ProbeProfile::Light],
        'healthThresholds' => ['working_after_successes' => 2, 'dead_after_failures' => 5],
        'quarantineRules' => ['AUTH_FAILURE' => 3],
    ];
}

function makePolicySnapshot(array $source): AuditPolicySnapshot
{
    return new AuditPolicySnapshot(
        id: 'policy-2026-08-26',
        policyVersion: 4,
        frozenAt: '2026-08-26T12:00:00+00:00',
        probeProfileMapping: $source['probeProfileMapping'],
        healthThresholds: $source['healthThresholds'],
        quarantineRules: $source['quarantineRules'],
    );
}

it('round-trips through JSON', function (): void {
    $snapshot = makePolicySnapshot(makePolicySource());

    $restored = AuditPolicySnapshot::fromJson($snapshot->jsonSerialize());

    expect($restored)->toEqual($snapshot);
});

it('requires the policyVersion field on deserialization', function (): void {
    $data = makePolicySnapshot(makePolicySource())->jsonSerialize();
    unset($data['policyVersion']);

    AuditPolicySnapshot::fromJson($data);
})->throws(RuntimeException::class, 'mandatory');

it('is an immutable copy: mutating the source does not change the snapshot', function (): void {
    $source = makePolicySource();
    $snapshot = makePolicySnapshot($snapshotSource = $source);
    $frozenJson = $snapshot->jsonSerialize();

    $source['probeProfileMapping']['manual'] = ProbeProfile::Light;
    $source['healthThresholds']['working_after_successes'] = 100;
    unset($source['quarantineRules']['AUTH_FAILURE']);

    expect($snapshot->probeProfileMapping['manual'])->toBe(ProbeProfile::Deep)
        ->and($snapshot->healthThresholds['working_after_successes'])->toBe(2)
        ->and($snapshot->quarantineRules)->toHaveKey('AUTH_FAILURE')
        ->and($snapshot->jsonSerialize())->toEqual($frozenJson)
        ->and($snapshotSource)->not->toBe($source);
});

it('keeps the snapshot untouched when the mapping source array is mutated before construction', function (): void {
    $mapping = ['feed' => ProbeProfile::Standard];
    $snapshot = new AuditPolicySnapshot(
        id: 'p',
        policyVersion: 1,
        frozenAt: '2026-08-26T12:00:00+00:00',
        probeProfileMapping: $mapping,
        healthThresholds: [],
        quarantineRules: [],
    );

    $mapping['feed'] = ProbeProfile::Light;

    expect($snapshot->probeProfileMapping['feed'])->toBe(ProbeProfile::Standard);
});
