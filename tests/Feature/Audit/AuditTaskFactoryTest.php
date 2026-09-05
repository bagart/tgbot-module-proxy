<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\AuditTaskFactory;
use BAGArt\ProxyOperations\Audit\CredentialSealer;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\AuditTrigger;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tests\Fixtures\RuntimeCredentialDecryptor;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set([
        'proxy-operations.encryption.kek' => 'proxy-enc-test-kek-v1',
        'proxy-operations.encryption.key_version' => 'k1',
        'proxy-operations.encryption.historical_keks' => [],
        'proxy-operations.audit.delivery.seal_key' => 'proxy-audit-seal-test-key',
    ]);

    $this->tenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($this->tenantId);

    $this->access = ProxyAccess::factory()->create();
    $this->job = ProxyAuditJob::factory()->withTrigger(AuditTrigger::Manual)->create();
    $this->attempt = ProxyAuditAttempt::factory()->create([
        'job_id' => $this->job->id,
        'attempt_no' => 1,
        'status' => AuditAttemptStatus::Pending,
    ]);
});

function taskFactory(): AuditTaskFactory
{
    return new AuditTaskFactory(
        sealer: app(CredentialSealer::class),
        maxAttempts: 3,
        deadlineSeconds: (int) config('proxy-operations.audit.delivery.task_deadline_seconds'),
        probeMaxOutputBytes: (int) config('proxy-operations.audit.delivery.probe_max_output_bytes'),
    );
}

it('builds a task with all snapshot fields and the correct access identity', function (): void {
    $task = taskFactory()->build($this->job, $this->attempt, $this->access);

    $endpoint = $this->access->endpoint()->firstOrFail();

    expect($task->job->jobId)->toBe($this->job->id)
        ->and($task->job->attemptId)->toBe($this->attempt->id)
        ->and($task->job->taskId)->toBeString()->not->toBe('')
        ->and(Str::isUlid($task->job->taskId))->toBeTrue()
        ->and($task->tenantId)->toBe((string) $this->job->tenant_id)
        ->and($task->accessRef->endpoint->host)->toBe($endpoint->canonical_host)
        ->and($task->accessRef->endpoint->port)->toBe($endpoint->port)
        ->and($task->accessRef->credential->value)->toBe($this->access->credentialFingerprint())
        ->and($task->policySnapshotVersion)->toBe($this->job->policySnapshot->policy_version)
        ->and($task->maxAttempts)->toBe(3)
        ->and($task->deadline)->toBeGreaterThanOrEqual(now()->toIso8601String());
});

it('derives the probe set from the snapshot probe profile mapping', function (): void {
    $task = taskFactory()->build($this->job, $this->attempt, $this->access);

    // Trigger "manual" maps to the standard profile: three probes, standard timeout.
    expect($task->probes)->toHaveCount(3)
        ->and($task->probes[0]->profile)->toBe(ProbeProfile::Standard)
        ->and($task->probes[0]->timeoutMs)->toBe((int) config('proxy-operations.checker.timeouts.standard'))
        ->and($task->probes[0]->target)->toBe($this->access->endpoint()->firstOrFail()->identity()->toString())
        ->and($task->probes[0]->maxOutputBytes)->toBe((int) config('proxy-operations.audit.delivery.probe_max_output_bytes'));
});

it('round-trips the credential through the runtime unsealer without leaking plaintext', function (): void {
    $secret = $this->access->credential()->firstOrFail()->secret_envelope;
    expect($secret)->not->toBeNull();

    $dekPlaintext = app(\BAGArt\ProxyOperations\Encryption\CredentialEncryptor::class)
        ->decrypt($this->tenantId, \BAGArt\ProxyOperations\Encryption\EncryptedField::fromJson((array) $secret));

    $task = taskFactory()->build($this->job, $this->attempt, $this->access);

    expect($task->sealedCredential)->toBeInstanceOf(SealedCredentialPayload::class)
        ->and($task->credentialReference)->toBeNull()
        ->and($task->sealedCredential->algId)->toBe(CredentialSealer::ALG_ID);

    $unsealed = (new RuntimeCredentialDecryptor)->decrypt($task->sealedCredential);

    expect($unsealed)->toBe($dekPlaintext);

    $serialized = json_encode($task, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain($dekPlaintext)
        ->and(json_encode($task->jsonSerialize(), JSON_THROW_ON_ERROR))->not->toContain($dekPlaintext);
});

it('rejects a tampered sealed payload with a generic error carrying no plaintext', function (): void {
    $task = taskFactory()->build($this->job, $this->attempt, $this->access);

    $sealed = $task->sealedCredential;
    $corrupted = new SealedCredentialPayload($sealed->algId, flipBase64Bit($sealed->ciphertext), $sealed->nonce);

    try {
        (new RuntimeCredentialDecryptor)->decrypt($corrupted);

        $this->fail('Expected the tampered payload to fail authentication.');
    } catch (RuntimeException $exception) {
        $dekPlaintext = decryptCredentialPlaintext($this->tenantId, $this->access);

        expect($exception->getMessage())->not->toContain($dekPlaintext);
    }
});

it('delivers credential-free accesses as an opaque reference instead of a sealed payload', function (): void {
    $bare = ProxyAccess::factory()->credentialFree()->create();

    $task = taskFactory()->build($this->job, $this->attempt, $bare);

    expect($task->sealedCredential)->toBeNull()
        ->and($task->credentialReference)->toBeInstanceOf(CredentialReference::class)
        ->and($task->credentialReference->handle)->toBe('credential-free');
});

it('takes the sealed payload TTL from the configuration', function (): void {
    expect(app(CredentialSealer::class)->ttlSeconds())
        ->toBe((int) config('proxy-operations.audit.delivery.sealed_ttl_seconds'));

    config()->set('proxy-operations.audit.delivery.sealed_ttl_seconds', 60);

    expect(new CredentialSealer(app(\BAGArt\ProxyOperations\Encryption\CredentialEncryptor::class), CredentialSealer::runtimeKeyFromConfig(), 60)->ttlSeconds())->toBe(60);
});

function flipBase64Bit(string $value): string
{
    $decoded = base64_decode($value, true);
    $decoded[0] = $decoded[0] ^ "\x5A";

    return base64_encode($decoded);
}

function decryptCredentialPlaintext(int $tenantId, ProxyAccess $access): string
{
    $envelope = $access->credential()->firstOrFail()->secret_envelope;

    return app(\BAGArt\ProxyOperations\Encryption\CredentialEncryptor::class)
        ->decrypt($tenantId, \BAGArt\ProxyOperations\Encryption\EncryptedField::fromJson((array) $envelope));
}
