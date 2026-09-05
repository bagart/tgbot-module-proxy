<?php

declare(strict_types=1);

use BAGArt\ASKClientRedis\Redis\ASKRedisClientFactory;
use BAGArt\ASKClientRedis\Redis\Contract\RedisClientContract;
use BAGArt\ASKClientRedis\Redis\RedisDsn;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Audit\RedisStreamsAuditDeliveryQueue;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

/**
 * Contract conformance over the real Redis Streams client (T18 precedent):
 * skipped when no Redis is reachable — the unit/feature tests run against
 * the in-memory fake.
 */
function auditDeliveryTestRedis(): ?RedisClientContract
{
    $dsn = (string) env('PROXY_AUDIT_TEST_REDIS_DSN', 'tcp://127.0.0.1:6379');

    try {
        $client = (new ASKRedisClientFactory)->create(RedisDsn::parse($dsn));
        $client->xLen('proxy:audit:tasks:conformance');
    } catch (Throwable) {
        return null;
    }

    return $client;
}

function conformanceTask(): AuditTaskV1
{
    return new AuditTaskV1(
        job: new JobRef('job-1', 'attempt-1', 'task-1'),
        tenantId: '42',
        accessRef: new AccessIdentity(
            new EndpointIdentity('198.51.100.7', 1080, ProxyProtocol::Socks5),
            new CredentialFingerprint('fingerprint'),
        ),
        sealedCredential: new SealedCredentialPayload('aes-256-gcm', base64_encode('ciphertext'), base64_encode('nonce')),
        credentialReference: null,
        probes: [new ProbeExecutionSpecV1(ProbeType::HttpLiveness, ProbeProfile::Light, 'http://judge-1.example.test/ping', 5000, 65536)],
        policySnapshotVersion: 1,
        deadline: '2026-08-26T13:00:00+00:00',
        maxAttempts: 3,
    );
}

it('conforms to the delivery queue contract over real Redis Streams', function (): void {
    $redis = auditDeliveryTestRedis();

    if ($redis === null) {
        markTestSkipped('No Redis available for the streams conformance test.');
    }

    $queue = new RedisStreamsAuditDeliveryQueue(
        redis: $redis,
        tasksStream: 'proxy:audit:tasks:conformance:'.uniqid(),
        resultsStream: 'proxy:audit:results:conformance:'.uniqid(),
    );

    $task = conformanceTask();
    $queue->enqueue($task);

    $result = new AuditResultV1(
        taskId: $task->job->taskId,
        attemptId: $task->job->attemptId,
        status: AuditResultStatus::Completed,
        observations: [new ProxyFailure((new FailureTaxonomy)->descriptor(FailureCode::TcpTimeout), ['probe' => 'http_liveness'])],
        executionFailures: [],
        timings: ['totalMs' => 123],
        checkerNodeId: 'checker-1',
    );

    $queue->enqueueResult($result);

    $consumed = $queue->consumeResults(10);

    expect($consumed)->toHaveCount(1)
        ->and($consumed[0]->taskId)->toBe($task->job->taskId)
        ->and($consumed[0]->status)->toBe(AuditResultStatus::Completed)
        ->and($queue->consumeResults(10))->toBe([]);
})->covers(RedisStreamsAuditDeliveryQueue::class);
