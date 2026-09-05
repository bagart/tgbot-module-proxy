<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfileDefinition;
use BAGArt\ProxyOperations\Encryption\EncryptedField;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Builds one AuditTaskV1 wire message for one attempt × access (plan §11.35
 * п.4): everything the worker needs to execute without touching Postgres.
 * tenant_id is METADATA ONLY — labels for logs/DLQ routing, never authz
 * (§11.30). The credential is unsealed from the DEK in-process and re-sealed
 * with the runtime key here; the plaintext never leaves build scope (INV-013).
 */
final class AuditTaskFactory
{
    public function __construct(
        private readonly CredentialSealer $sealer,
        private readonly int $maxAttempts,
        private readonly int $deadlineSeconds,
        private readonly int $probeMaxOutputBytes,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('The audit task maxAttempts must be at least 1.');
        }

        if ($deadlineSeconds < 1) {
            throw new InvalidArgumentException('The audit task deadline must be at least one second.');
        }

        if ($probeMaxOutputBytes < 1) {
            throw new InvalidArgumentException('The probe output cap must be at least one byte.');
        }
    }

    /**
     * Build one wire task for one attempt.
     *
     * @throws InvalidArgumentException When the trigger has no probe profile.
     */
    public function build(
        ProxyAuditJob $job,
        ProxyAuditAttempt $attempt,
        ProxyAccess $access,
    ): AuditTaskV1 {
        $snapshot = $job->policySnapshot->toDto();
        $profile = $snapshot->probeProfileMapping[$job->trigger->value]
            ?? throw new InvalidArgumentException(sprintf('The policy snapshot has no probe profile for trigger "%s".', $job->trigger->value));

        $definition = ProbeProfileDefinition::forProfile($profile);
        $identity = $access->endpoint()->firstOrFail()->identity();
        $timeoutMs = (int) config('proxy-operations.checker.timeouts.'.$definition->timeout->value, 15000);

        $probes = [];

        foreach ($definition->probes as $probeType) {
            $probes[] = new ProbeExecutionSpecV1(
                probeType: $probeType,
                profile: $profile,
                // Opaque target descriptor; the worker's runner resolves it
                // into the minimal ProbeInput per probe (plan §11.39 п.5).
                target: $identity->toString(),
                timeoutMs: $timeoutMs,
                maxOutputBytes: $this->probeMaxOutputBytes,
            );
        }

        [$sealed, $reference] = $this->credentialFor($job, $access);

        return new AuditTaskV1(
            job: new JobRef(
                jobId: $job->id,
                attemptId: $attempt->id,
                taskId: (string) Str::ulid(),
            ),
            tenantId: (string) $job->tenant_id,
            accessRef: new AccessIdentity(
                endpoint: $identity,
                credential: new CredentialFingerprint($access->credentialFingerprint() ?? ''),
            ),
            sealedCredential: $sealed,
            credentialReference: $reference,
            probes: $probes,
            policySnapshotVersion: $snapshot->policyVersion,
            deadline: now()->addSeconds($this->deadlineSeconds)->toIso8601String(),
            maxAttempts: $this->maxAttempts,
            accessId: $access->id,
        );
    }

    /**
     * Exactly one delivery mode per the wire contract: sealed payload for
     * accesses bound to a credential, an opaque credential-free reference for
     * bare endpoints (§11.2 — probed by bare EndpointIdentity).
     *
     * @return array{0: ?SealedCredentialPayload, 1: ?CredentialReference}
     */
    private function credentialFor(ProxyAuditJob $job, ProxyAccess $access): array
    {
        if ($access->credential_id === null) {
            return [null, new CredentialReference('credential-free')];
        }

        $envelope = EncryptedField::fromJson(
            (array) $access->credential()->firstOrFail()->secret_envelope,
        );

        return [$this->sealer->seal((int) $job->tenant_id, $envelope), null];
    }
}
