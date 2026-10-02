<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit\Consumers;

use BAGArt\ProxyOperations\Audit\AuditEventConsumer;
use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use Illuminate\Support\Facades\Log;

/**
 * Projects audit.completed events for observability and downstream consumers.
 *
 * Handles audit.completed events, logs state transitions, and invokes the
 * VerifiedProxyProjector to update the verified_proxies projection.
 *
 * Delivery semantics: at-least-once (dedup by eventId).
 * Tenant-scoped: the envelope's tenantId determines workspace context.
 */
final class AuditCompletedProjectionConsumer implements AuditEventConsumer
{
    private const string EVENT_TYPE = 'audit.completed';

    public function __construct(
        private readonly VerifiedProxyProjector $projector,
    ) {
    }

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

        if ($accessId !== null) {
            $access = ProxyAccess::query()
                ->where('tenant_id', $envelope->tenantId)
                ->whereKey($accessId)
                ->first();

            if ($access !== null) {
                $this->projector->project($access);
            }
        }
    }
}
