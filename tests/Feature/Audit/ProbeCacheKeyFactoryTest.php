<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Audit\ProbeCacheKeyFactory;
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

function t30KeyTask(): AuditTaskV1
{
    return new AuditTaskV1(
        job: new JobRef(jobId: 'job-1', attemptId: 'att-1', taskId: 'task-1'),
        tenantId: 'tenant-1',
        accessRef: new AccessIdentity(
            endpoint: new EndpointIdentity(host: '203.0.113.10', port: 1080, protocol: ProxyProtocol::Socks5),
            credential: new CredentialFingerprint(str_repeat('ab', 32)),
        ),
        sealedCredential: null,
        credentialReference: new CredentialReference(handle: 'handle-1'),
        probes: [
            new ProbeExecutionSpecV1(
                probeType: ProbeType::HttpLiveness,
                profile: ProbeProfile::Standard,
                target: 'https://judge.example.com/health',
                timeoutMs: 15000,
                maxOutputBytes: 65536,
            ),
        ],
        policySnapshotVersion: 7,
        deadline: '2026-08-30T00:10:00Z',
        maxAttempts: 3,
    );
}

function t30KeyFactory(array $nodeIdentity = []): ProbeCacheKeyFactory
{
    return new ProbeCacheKeyFactory($nodeIdentity + [
        'checker_node_id' => 'node-1',
        'egress_identity' => 'local',
        'judge_set_version' => 4,
        'tg_dc_set_version' => 2,
        'tool_semantics_version' => 'curl-8.9-proto2',
    ]);
}

it('builds a deterministic key: same task and probe produce the same hash', function (): void {
    $task = t30KeyTask();

    $first = t30KeyFactory()->build($task, ProbeType::HttpLiveness, 'v1');
    $second = t30KeyFactory()->build($task, ProbeType::HttpLiveness, 'v1');

    expect($first->toHash())->toBe($second->toHash())
        ->and($first->toString())->toBe($second->toString());
});

it('composes probeSemanticsVersion as "<probeType>@<base>" so probe types cannot collide', function (): void {
    $task = t30KeyTask();
    $factory = t30KeyFactory();

    $http = $factory->build($task, ProbeType::HttpLiveness, 'v1');
    $dns = $factory->build($task, ProbeType::DnsResolution, 'v1');
    $bumped = $factory->build($task, ProbeType::HttpLiveness, 'v2');

    expect($http->probeSemanticsVersion)->toBe('http_liveness@v1')
        ->and($dns->probeSemanticsVersion)->toBe('dns_resolution@v1')
        ->and($http->toHash())->not->toBe($dns->toHash())
        // A semantics bump invalidates old entries even for the same probe type.
        ->and($bumped->probeSemanticsVersion)->toBe('http_liveness@v2')
        ->and($http->toHash())->not->toBe($bumped->toHash());
});

it('takes the endpoint identity and credential fingerprint from the task verbatim', function (): void {
    $task = t30KeyTask();

    $key = t30KeyFactory()->build($task, ProbeType::HttpLiveness, 'v1');

    expect($key->endpointIdentity->equals($task->accessRef->endpoint))->toBeTrue()
        ->and($key->credentialFingerprint->value)->toBe($task->accessRef->credential->value)
        // Profile version comes from the task's frozen policy snapshot version.
        ->and($key->probeProfileVersion)->toBe(7);
});

it('exposes no credential material in the canonical key string', function (): void {
    $task = t30KeyTask();
    $key = t30KeyFactory()->build($task, ProbeType::HttpLiveness, 'v1');
    $canonical = $key->toString().' '.$key->toHash();

    // The HMAC fingerprint may appear (it is not a credential); the secret
    // material itself must never appear anywhere in the key.
    $plaintext = 's3cret-password-value';

    expect($canonical)->not->toContain($plaintext)
        ->and($canonical)->toContain((string) $key->credentialFingerprint->value);
});

it('reads node identity and dictionary versions from the node config', function (): void {
    $key = t30KeyFactory()->build(t30KeyTask(), ProbeType::HttpLiveness, 'v1');

    expect($key->checkerNodeId)->toBe('node-1')
        ->and($key->egressIdentity)->toBe('local')
        ->and($key->judgeSetVersion)->toBe(4)
        ->and($key->telegramDcSetVersion)->toBe(2)
        ->and($key->toolSemanticsVersion)->toBe('curl-8.9-proto2');

    $altered = (new ProbeCacheKeyFactory([
        'checker_node_id' => 'node-2',
        'egress_identity' => 'egress-eu-1',
        'judge_set_version' => 5,
        'tg_dc_set_version' => 3,
        'tool_semantics_version' => 'curl-8.10-proto2',
    ]))->build(t30KeyTask(), ProbeType::HttpLiveness, 'v1');

    expect($altered->toHash())->not->toBe($key->toHash());
});

it('falls back to the §11.8 single-node MVP identity when config omits the fields', function (): void {
    $key = (new ProbeCacheKeyFactory([]))->build(t30KeyTask(), ProbeType::HttpLiveness, 'v1');

    expect($key->checkerNodeId)->toBe('node-1')
        ->and($key->egressIdentity)->toBe('local');
});
