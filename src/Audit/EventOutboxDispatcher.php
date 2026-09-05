<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use BAGArt\ProxyOperations\Models\ProxyEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Post-commit outbox dispatcher (plan §§11.20, 11.35 пп.18–19, R6.7): selects
 * pending/failed proxy_events rows FOR UPDATE SKIP LOCKED, stamps the attempt
 * metadata inside a claiming transaction, then — after that transaction has
 * committed — hands the envelopes to the registered AuditEventConsumer set.
 *
 * At-least-once semantics: consumers run after the claim commit, so a crash
 * between claim and mark leaves the row claimable again and consumers see the
 * envelope more than once (they dedup by event_id). A consumer throw marks the
 * row failed (attempts already counted); failed rows are retried on the next
 * run. Dispatched rows are never deleted. Must never be invoked inside the
 * ingestion transaction — projections and notifications happen strictly after
 * the source commit (§11.35 п.18).
 */
final class EventOutboxDispatcher
{
    public function __construct(
        private readonly array $consumers,
        private readonly int $batchSize = 100,
    ) {
        foreach ($consumers as $consumer) {
            if (! $consumer instanceof AuditEventConsumer) {
                throw new InvalidArgumentException('Outbox consumers must implement AuditEventConsumer.');
            }
        }
    }

    /**
     * One dispatch pass over the claimable backlog.
     *
     * @return int The number of envelopes handed to consumers (including
     *             attempts that failed — those stay queued for retry).
     */
    public function dispatch(?int $batchSize = null): int
    {
        // Claim window: lock the batch and stamp the attempt inside one short
        // transaction so concurrent dispatchers (FOR UPDATE SKIP LOCKED) never
        // receive the same row in the same pass.
        /** @var list<EventEnvelope> $batch */
        $batch = DB::transaction(function () use ($batchSize): array {
            $rows = ProxyEvent::query()
                ->whereIn('dispatch_status', [ProxyEvent::STATUS_PENDING, ProxyEvent::STATUS_FAILED])
                ->orderBy('tenant_id')
                ->orderBy('sequence')
                ->limit($batchSize ?? $this->batchSize)
                ->lockForUpdate() // FOR UPDATE SKIP LOCKED on PostgreSQL
                ->get();

            foreach ($rows as $row) {
                $row->forceFill([
                    'attempt_count' => $row->attempt_count + 1,
                    'last_attempt_at' => now(),
                ])->save();
            }

            return $rows->map(fn (ProxyEvent $row): EventEnvelope => $row->toEnvelope())->all();
        });

        // Post-claim (post-commit) delivery — at-least-once.
        $delivered = 0;

        foreach ($batch as $envelope) {
            try {
                foreach ($this->consumers as $consumer) {
                    $consumer->handle($envelope);
                }
            } catch (Throwable) {
                $this->mark($envelope->eventId, [
                    'dispatch_status' => ProxyEvent::STATUS_FAILED,
                    'last_attempt_at' => now(),
                ]);

                $delivered++;

                continue;
            }

            $this->mark($envelope->eventId, [
                'dispatch_status' => ProxyEvent::STATUS_DISPATCHED,
                'last_attempt_at' => now(),
                'consumed_at' => now(),
            ]);

            $delivered++;
        }

        return $delivered;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function mark(string $eventId, array $attributes): void
    {
        $row = ProxyEvent::query()->whereKey($eventId)->first();

        if ($row !== null) {
            $row->forceFill($attributes)->save();
        }
    }
}
