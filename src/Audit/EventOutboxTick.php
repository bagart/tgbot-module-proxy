<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\ProxyOperations\Models\ProxyEvent;
use Throwable;

/**
 * ASK tickable adapter for the outbox dispatcher (T18 daemon tickable
 * pattern): one tick = one dispatch pass over the pending/failed backlog.
 * Delivery failures stay queued inside proxy_events (at-least-once), so a
 * tick that throws is safe — the adapter swallows nothing but keeps the
 * pressure signal honest via the pending queue size.
 */
final class EventOutboxTick implements ASKTickableContract
{
    private int $lastDispatched = 0;

    public function __construct(
        private readonly EventOutboxDispatcher $dispatcher,
        private readonly int $batchSize = 100,
        private readonly string $name = 'EventOutboxTick',
    ) {
    }

    public function tick(int $systemPressure): void
    {
        try {
            $this->lastDispatched = $this->dispatcher->dispatch($this->batchSize);
        } catch (Throwable) {
            // The backlog stays queued — zero loss; the next tick retries.
            $this->lastDispatched = 0;
        }
    }

    public function pressure(): int
    {
        $queueSize = $this->queueSize();

        if ($queueSize === 0) {
            return 0;
        }

        return min(100, (int) ceil($queueSize / max(1, $this->batchSize) * 100));
    }

    public function isIdle(): bool
    {
        return $this->queueSize() === 0;
    }

    public function queueSize(): int
    {
        return ProxyEvent::query()
            ->whereIn('dispatch_status', [ProxyEvent::STATUS_PENDING, ProxyEvent::STATUS_FAILED])
            ->count();
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Envelopes handed to consumers during the last tick (0 on failure).
     */
    public function lastDispatched(): int
    {
        return $this->lastDispatched;
    }
}
