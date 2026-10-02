<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

function taskJobRef(): JobRef
{
    return new JobRef(jobId: 'job-1', attemptId: 'attempt-1', taskId: 'task-1');
}

function taskAccessRef(): AccessIdentity
{
    return new AccessIdentity(
        endpoint: new EndpointIdentity('192.0.2.10', 1080, ProxyProtocol::Socks5),
        credential: new CredentialFingerprint(str_repeat('ab', 32)),
    );
}

function taskSealed(): SealedCredentialPayload
{
    return new SealedCredentialPayload(
        algId: 'aes-256-gcm',
        ciphertext: base64_encode('ciphertext-bytes'),
        nonce: base64_encode('nonce-bytes'),
    );
}

function taskReference(): CredentialReference
{
    return new CredentialReference(handle: 'unseal:node-7:9f8e');
}

function taskProbes(): array
{
    return [new ProbeExecutionSpecV1(
        probeType: ProbeType::HttpLiveness,
        profile: ProbeProfile::Light,
        target: 'http://judge-1.example.test/ping',
        timeoutMs: 5000,
        maxOutputBytes: 65536,
    )];
}

it('round-trips through JSON with a sealed credential payload', function (): void {
    $task = new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: taskSealed(),
        credentialReference: null,
        probes: taskProbes(),
        policySnapshotVersion: 4,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    );

    $restored = AuditTaskV1::fromJson($task->jsonSerialize());

    expect($restored)->toEqual($task)
        ->and($restored->sealedCredential)->toEqual(taskSealed())
        ->and($restored->credentialReference)->toBeNull();
});

it('round-trips through JSON with a credential reference', function (): void {
    $task = new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: null,
        credentialReference: taskReference(),
        probes: taskProbes(),
        policySnapshotVersion: 4,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    );

    $restored = AuditTaskV1::fromJson($task->jsonSerialize());

    expect($restored)->toEqual($task)
        ->and($restored->credentialReference)->toEqual(taskReference())
        ->and($restored->sealedCredential)->toBeNull();
});

it('carries tenantId as metadata only, alongside the full execution snapshot', function (): void {
    $json = (new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: null,
        credentialReference: taskReference(),
        probes: taskProbes(),
        policySnapshotVersion: 4,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    ))->jsonSerialize();

    expect($json['tenantId'])->toBe('tenant-42')
        ->and($json['accessRef'])->toHaveKey('endpoint')
        ->and($json['policySnapshotVersion'])->toBe(4)
        ->and($json['deadline'])->toBe('2026-08-26T13:00:00+00:00')
        ->and($json['maxAttempts'])->toBe(3);
});

it('rejects an unknown schemaVersion', function (): void {
    $data = (new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: taskSealed(),
        credentialReference: null,
        probes: taskProbes(),
        policySnapshotVersion: 4,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    ))->jsonSerialize();
    $data['schemaVersion'] = 99;

    AuditTaskV1::fromJson($data);
})->throws(RuntimeException::class, 'Unsupported AuditTask schemaVersion');

it('rejects both credential delivery modes at once', function (): void {
    new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: taskSealed(),
        credentialReference: taskReference(),
        probes: taskProbes(),
        policySnapshotVersion: 4,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    );
})->throws(InvalidArgumentException::class, 'exactly one credential delivery mode');

it('rejects a task without any credential delivery mode', function (): void {
    new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: null,
        credentialReference: null,
        probes: taskProbes(),
        policySnapshotVersion: 4,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    );
})->throws(InvalidArgumentException::class, 'exactly one credential delivery mode');

it('requires the deadline', function (): void {
    new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: taskSealed(),
        credentialReference: null,
        probes: taskProbes(),
        policySnapshotVersion: 4,
        deadline: '',
        maxAttempts: 3,
    );
})->throws(InvalidArgumentException::class, 'deadline');

it('rejects foreign probe entries', function (): void {
    new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: taskSealed(),
        credentialReference: null,
        probes: [new stdClass()],
        policySnapshotVersion: 4,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    );
})->throws(InvalidArgumentException::class, 'ProbeExecutionSpecV1 instances');

it('rejects an unknown credential mode on deserialization', function (): void {
    $data = (new AuditTaskV1(
        job: taskJobRef(),
        tenantId: 'tenant-42',
        accessRef: taskAccessRef(),
        sealedCredential: taskSealed(),
        credentialReference: null,
        probes: taskProbes(),
        policySnapshotVersion: 4,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    ))->jsonSerialize();
    $data['credential']['mode'] = 'plaintext';

    AuditTaskV1::fromJson($data);
})->throws(InvalidArgumentException::class, 'exactly one credential delivery mode');
