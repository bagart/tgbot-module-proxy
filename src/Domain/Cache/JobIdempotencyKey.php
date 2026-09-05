<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Cache;

use RuntimeException;

/**
 * Import/trigger-level idempotency key (plan §11.19): dedupes creation of an
 * audit job from the Application API `Idempotency-Key` header or a normalized
 * import batch hash. Deliberately a different type from
 * TaskDeliveryIdempotencyKey — the sources are not interchangeable and are
 * stored in separate dedup stores with separate TTLs.
 */
final readonly class JobIdempotencyKey
{
    public const string SCOPE = 'job';

    private function __construct(
        public readonly string $value,
    ) {}

    /**
     * @throws RuntimeException If the client key is empty.
     */
    public static function fromApiClientKey(string $idempotencyKey): self
    {
        if ($idempotencyKey === '') {
            throw new RuntimeException('JobIdempotencyKey requires a non-empty client key.');
        }

        return new self(self::SCOPE.':'.hash('sha256', "api\x00".$idempotencyKey));
    }

    /**
     * Placement idempotency key (plan §11.18): dedupes creation of an audit
     * job within the TTL window by (tenant, trigger, target set, snapshot) —
     * a duplicate placement inside the window returns the existing job.
     *
     * @param  non-empty-string  $trigger  AuditTrigger value.
     * @param  non-empty-string  $targetSetHash  Hash over the sorted access ids.
     * @param  non-empty-string  $policySnapshotId  Snapshot the job will reference.
     */
    public static function fromPlacement(
        int $tenantId,
        string $trigger,
        string $targetSetHash,
        string $policySnapshotId,
    ): self {
        return new self(self::SCOPE.':'.hash('sha256', "placement\x00".$tenantId."\x00".$trigger."\x00".$targetSetHash."\x00".$policySnapshotId));
    }

    /**
     * @param  string  $normalizedBatchHash  Hash over normalized import input.
     *
     * @throws RuntimeException If the batch hash is empty.
     */
    public static function fromImportBatchHash(string $normalizedBatchHash): self
    {
        if ($normalizedBatchHash === '') {
            throw new RuntimeException('JobIdempotencyKey requires a non-empty batch hash.');
        }

        return new self(self::SCOPE.':'.hash('sha256', "import_batch\x00".$normalizedBatchHash));
    }
}
