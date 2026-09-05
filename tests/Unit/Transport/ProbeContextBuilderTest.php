<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Transport\ProbeContextBuilder;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

function makeAuditTaskWithSealedCredential(): AuditTaskV1
{
    return new AuditTaskV1(
        job: new JobRef(jobId: 'job-1', attemptId: 'att-1', taskId: 'task-1'),
        tenantId: 'tenant-1',
        accessRef: new AccessIdentity(
            endpoint: new EndpointIdentity(host: '10.0.0.1', port: 1080, protocol: ProxyProtocol::Socks5),
            credential: new CredentialFingerprint(value: 'fp-abc'),
        ),
        sealedCredential: new SealedCredentialPayload(algId: 'aes-256-gcm', ciphertext: 'deadbeef', nonce: '0102'),
        credentialReference: null,
        probes: [],
        policySnapshotVersion: 1,
        deadline: '2026-12-31T23:59:59Z',
        maxAttempts: 3,
    );
}

function makeAuditTaskWithCredentialReference(): AuditTaskV1
{
    return new AuditTaskV1(
        job: new JobRef(jobId: 'job-2', attemptId: 'att-2', taskId: 'task-2'),
        tenantId: 'tenant-2',
        accessRef: new AccessIdentity(
            endpoint: new EndpointIdentity(host: 'proxy.example.com', port: 8080, protocol: ProxyProtocol::Http),
            credential: new CredentialFingerprint(value: 'fp-def'),
        ),
        sealedCredential: null,
        credentialReference: new CredentialReference(handle: 'unseal:node-7:9f8e'),
        probes: [],
        policySnapshotVersion: 1,
        deadline: '2026-12-31T23:59:59Z',
        maxAttempts: 3,
    );
}

function makeProbeSpec(): ProbeExecutionSpecV1
{
    return new ProbeExecutionSpecV1(
        probeType: ProbeType::HttpLiveness,
        profile: ProbeProfile::Standard,
        target: 'https://example.com/health',
        timeoutMs: 5000,
        maxOutputBytes: 65536,
    );
}

it('builds context from AuditTaskV1 with sealed credential', function (): void {
    $builder = new ProbeContextBuilder;
    $context = $builder->fromAuditTask(
        task: makeAuditTaskWithSealedCredential(),
        probe: makeProbeSpec(),
    );

    expect($context->host)->toBe('10.0.0.1');
    expect($context->port)->toBe(1080);
    expect($context->spec->probeType)->toBe(ProbeType::HttpLiveness);
    expect($context->spec->target)->toBe('https://example.com/health');
    expect($context->timeoutMs)->toBe(5000);
    expect($context->maxOutputBytes)->toBe(65536);
});

it('builds context with StdinChannel for sealed credential', function (): void {
    $builder = new ProbeContextBuilder;
    $context = $builder->fromAuditTask(
        task: makeAuditTaskWithSealedCredential(),
        probe: makeProbeSpec(),
    );

    expect($context->credentials)->toBeInstanceOf(StdinChannel::class);
});

it('builds context with FileDescriptorChannel for credential reference', function (): void {
    $builder = new ProbeContextBuilder;
    $context = $builder->fromAuditTask(
        task: makeAuditTaskWithCredentialReference(),
        probe: makeProbeSpec(),
    );

    expect($context->credentials)->toBeInstanceOf(FileDescriptorChannel::class);
});

it('does not propagate AccessIdentity to ProbeExecutionContext', function (): void {
    $builder = new ProbeContextBuilder;
    $context = $builder->fromAuditTask(
        task: makeAuditTaskWithSealedCredential(),
        probe: makeProbeSpec(),
    );

    $reflection = new ReflectionClass($context);
    $constructor = $reflection->getConstructor();
    assert($constructor !== null);

    foreach ($constructor->getParameters() as $parameter) {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType) {
            expect($type->getName())->not->toBe(AccessIdentity::class);
        }
    }
});

it('does not propagate TenantId to ProbeExecutionContext', function (): void {
    $builder = new ProbeContextBuilder;
    $context = $builder->fromAuditTask(
        task: makeAuditTaskWithSealedCredential(),
        probe: makeProbeSpec(),
    );

    $reflection = new ReflectionClass($context);
    $constructor = $reflection->getConstructor();
    assert($constructor !== null);

    $paramNames = array_map(
        static fn (ReflectionParameter $param): string => $param->getName(),
        $constructor->getParameters(),
    );

    expect($paramNames)->not->toContain('tenantId');
    expect($paramNames)->not->toContain('tenant');
});

it('passes probe timeout and output limit through', function (): void {
    $probe = new ProbeExecutionSpecV1(
        probeType: ProbeType::LatencySeries,
        profile: ProbeProfile::Deep,
        target: 'https://judge.example.com/ping',
        timeoutMs: 10000,
        maxOutputBytes: 131072,
    );

    $builder = new ProbeContextBuilder;
    $context = $builder->fromAuditTask(
        task: makeAuditTaskWithSealedCredential(),
        probe: $probe,
    );

    expect($context->timeoutMs)->toBe(10000);
    expect($context->maxOutputBytes)->toBe(131072);
    expect($context->spec->probeType)->toBe(ProbeType::LatencySeries);
    expect($context->spec->target)->toBe('https://judge.example.com/ping');
});
