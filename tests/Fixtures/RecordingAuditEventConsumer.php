<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Fixtures;

use BAGArt\ProxyOperations\Audit\AuditEventConsumer;
use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording AuditEventConsumer fake for outbox tests: handles each envelope
 * once (dedup by event_id — the at-least-once consumer contract), records the
 * DB transaction depth at handle time (post-commit ordering assertions), and
 * can simulate one consumer outage.
 */
final class RecordingAuditEventConsumer implements AuditEventConsumer
{
    /** @var list<EventEnvelope> */
    public array $handled = [];

    /**
     * Event id → DB::transactionLevel() at handle time.
     *
     * @var array<non-empty-string, int>
     */
    public array $transactionLevels = [];

    /** @var list<string> */
    public array $deliveries = [];

    public bool $throwOnNextHandle = false;

    private array $seen = [];

    public function handle(EventEnvelope $envelope): void
    {
        $this->deliveries[] = $envelope->eventId;

        if ($this->throwOnNextHandle) {
            $this->throwOnNextHandle = false;

            throw new RuntimeException('Event consumer is down (simulated).');
        }

        // At-least-once delivery: the consumer dedups by event_id.
        if (isset($this->seen[$envelope->eventId])) {
            return;
        }

        $this->seen[$envelope->eventId] = true;
        $this->transactionLevels[$envelope->eventId] = DB::transactionLevel();
        $this->handled[] = $envelope;
    }

    /**
     * @return list<non-empty-string>
     */
    public function handledEventIds(): array
    {
        return array_map(fn (EventEnvelope $envelope): string => $envelope->eventId, $this->handled);
    }
}
