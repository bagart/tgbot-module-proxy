<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Cache;

use BAGArt\ProxyOperations\Wire\JobRef;
use RuntimeException;

/**
 * Worker-delivery-level idempotency key (plan §11.9, §11.19): dedupes
 * processing of one task delivery as taskId+attemptId, backed by the attempts
 * unique constraint. Deliberately a different type from JobIdempotencyKey —
 * worker results and job triggers must never share a dedup namespace.
 */
final readonly class TaskDeliveryIdempotencyKey
{
    public const string SCOPE = 'task_delivery';

    private function __construct(
        public readonly string $value,
    ) {}

    /**
     * @throws RuntimeException If the JobRef carries empty ids.
     */
    public static function fromJobRef(JobRef $ref): self
    {
        if ($ref->attemptId === '' || $ref->taskId === '') {
            throw new RuntimeException('TaskDeliveryIdempotencyKey requires non-empty taskId and attemptId.');
        }

        return new self(self::SCOPE.':'.hash('sha256', $ref->taskId."\x00".$ref->attemptId));
    }
}
