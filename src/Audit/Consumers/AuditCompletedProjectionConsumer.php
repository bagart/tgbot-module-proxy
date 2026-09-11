<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit\Consumers;

use BAGArt\ProxyOperations\Audit\AuditEventConsumer;
use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use Illuminate\Support\Facades\Log;

/**
 * Projects audit.completed events for observability and downstream consumers.
 *
 * This is the first concrete AuditEventConsumer — it handles audit.completed
 * events and logs state transitions. Designed to be extended later for:
 * - verified_proxies projection (plan §11.15 п.1)
 * - notification dispatch on state changes
 * - external read model updates
 *
 * Delivery semantics: at-least-once (dedup by eventId).
 * Tenant-scoped: the envelope's tenantId determines workspace context.
 */
final class AuditCompletedProjectionConsumer implements AuditEventConsumer
{
    private const string EVENT_TYPE = 'audit.completed';

    public function handle(EventEnvelope $envelope): void
    {
        if ($envelope->eventType !== self::EVENT_TYPE) {
            return;
        }

        $payload = $envelope->payload;
        $accessId = $payload['accessId'] ?? null;
        $stateTransition = $payload['stateTransition'] ?? null;
        $status = $payload['status'] ?? null;

        Log::info('proxy.audit.completed', [
            'event_id' => $envelope->eventId,
            'tenant_id' => $envelope->tenantId,
            'access_id' => $accessId,
            'status' => $status,
            'state_transition' => $stateTransition,
            'occurred_at' => $envelope->occurredAt,
        ]);

        if ($stateTransition !== null) {
            Log::info('proxy.audit.state_transition', [
                'event_id' => $envelope->eventId,
                'tenant_id' => $envelope->tenantId,
                'access_id' => $accessId,
                'new_state' => $stateTransition,
            ]);
        }
    }
}
