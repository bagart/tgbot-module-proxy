<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Cache;

/**
 * Per-aggregate strict ordering policy for events (plan §11.20).
 *
 * The outbox pattern delivers at-least-once, so the dispatcher may repeat or
 * reorder deliveries across aggregates; consumers keep the last-seen
 * SequenceNumber per EventEnvelope::$aggregateRef and use this policy to
 * decide whether an incoming event may be applied. Duplicates are handled by
 * eventId idempotency at the consumer before this check; anything that is not
 * exactly `lastSeen + 1` (gap, replay, out-of-order) is rejected here.
 */
final readonly class EventOrderingPolicy
{
    /**
     * @param  SequenceNumber|null  $lastSeen  Last applied sequence for the aggregate; null before the first event.
     */
    public function isNext(?SequenceNumber $lastSeen, SequenceNumber $candidate): bool
    {
        if ($lastSeen === null) {
            return $candidate->value === 1;
        }

        return $candidate->value === $lastSeen->value + 1;
    }
}
