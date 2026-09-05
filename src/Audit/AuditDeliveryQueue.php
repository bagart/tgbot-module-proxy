<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Wire\AuditResultV1;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;

/**
 * Transport seam between the application layer and the checker worker
 * (plan §11.9). Production binds the Redis Streams implementation; tests use
 * an in-memory fake. The consumer dedups by task_id + attempt_id (§11.19), so
 * the transport itself may be at-least-once.
 */
interface AuditDeliveryQueue
{
    public function enqueue(AuditTaskV1 $task): void;

    /** @return list<AuditResultV1> */
    public function consumeResults(int $max): array;

    public function enqueueResult(AuditResultV1 $result): void; // worker-side push, test/dlq use
}
