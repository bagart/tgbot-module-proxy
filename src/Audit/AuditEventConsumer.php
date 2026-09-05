<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;

/**
 * A consumer of dispatched outbox events (plan §§11.20, 11.35 п.19).
 * Delivery is at-least-once — implementations must dedup by
 * EventEnvelope::$eventId and order per aggregate by the row sequence.
 * Registered in the container (tagged `proxy-operations.audit-event-consumers`).
 */
interface AuditEventConsumer
{
    public function handle(EventEnvelope $envelope): void;
}
