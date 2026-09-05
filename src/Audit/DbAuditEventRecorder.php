<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use BAGArt\ProxyOperations\Domain\Cache\SequenceNumber;
use BAGArt\ProxyOperations\Models\ProxyEvent;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Production AuditEventRecorder (plan §§11.20, 11.35 п.18, §11.37 R6.7):
 * appends the envelope as a proxy_events row — event log and transactional
 * outbox in one table. Called strictly inside the ingestion transaction
 * (T26), so the event commits atomically with the observation/health/lifecycle
 * writes it was derived from; dispatch is strictly post-commit.
 *
 * The per-tenant `sequence` is derived as MAX(sequence) + 1 inside the same
 * transaction (T92 SequenceNumber) and enforced monotonic by the
 * (tenant_id, sequence) unique constraint. Unknown event types fail closed
 * against the EventTypeRegistry. Rows are never deleted here or anywhere —
 * ProxyEvent guards deletes with ImmutableRecordException.
 */
final class DbAuditEventRecorder implements AuditEventRecorder
{
    public function __construct(
        private readonly EventTypeRegistry $registry,
    ) {}

    public function record(EventEnvelope $envelope): void
    {
        if (! $this->registry->has($envelope->eventType)) {
            throw new InvalidArgumentException("Unknown event type: {$envelope->eventType}.");
        }

        $tenantId = (int) $envelope->tenantId;

        $sequence = (int) (ProxyEvent::query()
            ->where('tenant_id', $tenantId)
            ->max('sequence') ?? 0) + 1;

        ProxyEvent::query()->create([
            'id' => $envelope->eventId,
            'tenant_id' => $tenantId,
            'event_type' => $envelope->eventType,
            'schema_version' => $this->registry->schemaVersion($envelope->eventType),
            'occurred_at' => new DateTimeImmutable($envelope->occurredAt),
            'aggregate_type' => $this->registry->aggregateType($envelope->eventType),
            'aggregate_id' => $envelope->aggregateRef,
            'payload' => $envelope->payload,
            'sequence' => (new SequenceNumber($sequence))->value,
            'dispatch_status' => ProxyEvent::STATUS_PENDING,
        ]);
    }
}
