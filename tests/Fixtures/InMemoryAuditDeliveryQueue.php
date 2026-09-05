<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Fixtures;

use BAGArt\ProxyOperations\Audit\AuditDeliveryQueue;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use RuntimeException;

/**
 * In-memory AuditDeliveryQueue fake for unit/feature tests: records enqueued
 * tasks/results, can simulate Redis unavailability on enqueue.
 */
final class InMemoryAuditDeliveryQueue implements AuditDeliveryQueue
{
    /** @var list<AuditTaskV1> */
    public array $tasks = [];

    /** @var list<AuditResultV1> */
    public array $results = [];

    public bool $throwOnEnqueue = false;

    private int $cursor = 0;

    public function enqueue(AuditTaskV1 $task): void
    {
        if ($this->throwOnEnqueue) {
            throw new RuntimeException('Redis is unavailable (simulated).');
        }

        $this->tasks[] = $task;
    }

    public function consumeResults(int $max): array
    {
        if ($max < 1) {
            return [];
        }

        $slice = array_slice($this->results, $this->cursor, $max);
        $this->cursor += count($slice);

        return $slice;
    }

    public function enqueueResult(AuditResultV1 $result): void
    {
        $this->results[] = $result;
    }
}
