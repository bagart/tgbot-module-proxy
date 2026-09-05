<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\AuditEventRecorder;
use BAGArt\ProxyOperations\Audit\DbAuditEventRecorder;
use BAGArt\ProxyOperations\Audit\EventOutboxDispatcher;
use BAGArt\ProxyOperations\Audit\EventOutboxTick;
use BAGArt\ProxyOperations\Audit\EventTypeRegistry;
use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use BAGArt\ProxyOperations\Models\ProxyEvent;
use BAGArt\ProxyOperations\Models\ImmutableRecordException;
use BAGArt\ProxyOperations\Tests\Fixtures\RecordingAuditEventConsumer;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenantId = User::factory()->create()->id;

    $this->recorder = new DbAuditEventRecorder(app(EventTypeRegistry::class));
    $this->consumer = new RecordingAuditEventConsumer;
    $this->dispatcher = new EventOutboxDispatcher([$this->consumer]);
});

function outboxEnvelope(string $tenantId, string $aggregateId = 'access-1'): EventEnvelope
{
    return new EventEnvelope(
        eventId: EventEnvelope::generateId(),
        eventType: 'audit.completed',
        occurredAt: now()->toIso8601String(),
        tenantId: $tenantId,
        aggregateRef: $aggregateId,
        payload: ['attemptId' => 'attempt-1'],
    );
}

it('records envelope rows with catalog schema version and per-tenant sequence ordering', function (): void {
    $this->recorder->record($first = outboxEnvelope((string) $this->tenantId));
    $this->recorder->record($second = outboxEnvelope((string) $this->tenantId, 'access-2'));

    // Sequences are per tenant: another tenant starts at 1 again.
    $otherTenantId = User::factory()->create()->id;
    $this->recorder->record(outboxEnvelope((string) $otherTenantId));

    $rows = ProxyEvent::query()->orderBy('tenant_id')->orderBy('sequence')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->id)->toBe($first->eventId)
        ->and($rows[0]->event_type)->toBe('audit.completed')
        ->and($rows[0]->schema_version)->toBe(1)
        ->and($rows[0]->aggregate_type)->toBe('proxy_access')
        ->and($rows[0]->aggregate_id)->toBe('access-1')
        ->and($rows[0]->payload)->toBe(['attemptId' => 'attempt-1'])
        ->and($rows[0]->sequence)->toBe(1)
        ->and($rows[0]->dispatch_status)->toBe(ProxyEvent::STATUS_PENDING)
        ->and($rows[0]->attempt_count)->toBe(0)
        ->and($rows[0]->consumed_at)->toBeNull()
        ->and($rows[1]->id)->toBe($second->eventId)
        ->and($rows[1]->sequence)->toBe(2)
        ->and($rows[2]->tenant_id)->toBe($otherTenantId)
        ->and($rows[2]->sequence)->toBe(1);
});

it('fails closed on an event type outside the catalog', function (): void {
    $this->recorder->record(new EventEnvelope(
        eventId: EventEnvelope::generateId(),
        eventType: 'rogue.event',
        occurredAt: now()->toIso8601String(),
        tenantId: (string) $this->tenantId,
        aggregateRef: 'access-1',
        payload: [],
    ));
})->throws(InvalidArgumentException::class, 'Unknown event type');

it('guards the envelope part as immutable while dispatch columns stay updatable', function (): void {
    $this->recorder->record(outboxEnvelope((string) $this->tenantId));

    $row = ProxyEvent::query()->firstOrFail();

    // Payload, occurred_at and identity never change (R6.7)...
    foreach ([
        'payload' => ['tampered' => true],
        'occurred_at' => now()->subYear(),
        'event_type' => 'worker.failed',
        'aggregate_id' => 'other-access',
        'sequence' => 999,
    ] as $column => $value) {
        try {
            $row->forceFill([$column => $value])->save();

            $this->fail("Expected {$column} mutation to be rejected.");
        } catch (ImmutableRecordException) {
            // expected — and rolled back with the row untouched
            expect($row->refresh()->isDirty($column))->toBeFalse();
        }
    }

    // ...rows are never deleted...
    try {
        $row->delete();
        $this->fail('Expected the delete to be rejected.');
    } catch (ImmutableRecordException) {
        // expected
    }
    expect(ProxyEvent::query()->count())->toBe(1);

    // ...but the dispatcher owns the mutable dispatch metadata.
    $row->forceFill([
        'dispatch_status' => ProxyEvent::STATUS_DISPATCHED,
        'attempt_count' => 1,
        'last_attempt_at' => now(),
        'consumed_at' => now(),
    ])->save();

    expect($row->refresh()->dispatch_status)->toBe(ProxyEvent::STATUS_DISPATCHED)
        ->and($row->attempt_count)->toBe(1)
        ->and($row->consumed_at)->not->toBeNull();
});

it('dispatches pending rows to consumers and marks them dispatched with consumed_at', function (): void {
    $this->recorder->record($envelope = outboxEnvelope((string) $this->tenantId));

    $handled = $this->dispatcher->dispatch();

    $row = ProxyEvent::query()->findOrFail($envelope->eventId);

    expect($handled)->toBe(1)
        ->and($this->consumer->handledEventIds())->toBe([$envelope->eventId])
        ->and($row->dispatch_status)->toBe(ProxyEvent::STATUS_DISPATCHED)
        ->and($row->consumed_at)->not->toBeNull()
        ->and($row->attempt_count)->toBe(1)
        ->and($row->last_attempt_at)->not->toBeNull();

    $restored = $this->consumer->handled[0];

    expect($restored->eventId)->toBe($envelope->eventId)
        ->and($restored->eventType)->toBe($envelope->eventType)
        ->and($restored->aggregateRef)->toBe($envelope->aggregateRef)
        ->and($restored->payload)->toBe($envelope->payload);
});

it('marks failed rows with attempts and retries them on the next run', function (): void {
    $this->recorder->record($envelope = outboxEnvelope((string) $this->tenantId));

    $this->consumer->throwOnNextHandle = true;
    expect($this->dispatcher->dispatch())->toBe(1);

    $row = ProxyEvent::query()->findOrFail($envelope->eventId);

    expect($row->dispatch_status)->toBe(ProxyEvent::STATUS_FAILED)
        ->and($row->attempt_count)->toBe(1)
        ->and($row->consumed_at)->toBeNull();

    // At-least-once: the retry redelivers; the consumer dedups by event_id.
    expect($this->dispatcher->dispatch())->toBe(1);

    $row->refresh();

    expect($row->dispatch_status)->toBe(ProxyEvent::STATUS_DISPATCHED)
        ->and($row->attempt_count)->toBe(2)
        ->and($row->consumed_at)->not->toBeNull()
        ->and($this->consumer->deliveries)->toBe([$envelope->eventId, $envelope->eventId])
        ->and($this->consumer->handledEventIds())->toBe([$envelope->eventId]);
});

it('never hands the same row to two dispatch passes', function (): void {
    $this->recorder->record(outboxEnvelope((string) $this->tenantId));
    $this->recorder->record(outboxEnvelope((string) $this->tenantId, 'access-2'));

    expect($this->dispatcher->dispatch())->toBe(2);

    // Second run (and a second dispatcher instance over the same table):
    // everything is dispatched, nothing is claimable, nobody is called again.
    $second = new EventOutboxDispatcher([new RecordingAuditEventConsumer]);

    expect($second->dispatch())->toBe(0)
        ->and($this->consumer->deliveries)->toHaveCount(2);
});

it('keeps consumer handling strictly outside the claiming transaction', function (): void {
    $this->recorder->record($envelope = outboxEnvelope((string) $this->tenantId));

    $outerLevel = DB::transactionLevel();

    $this->dispatcher->dispatch();

    // Consumers run after the claim transaction has committed (§11.35 п.18):
    // the transaction depth at handle time equals the outer depth, not deeper.
    expect($this->consumer->transactionLevels[$envelope->eventId])->toBe($outerLevel);
});

it('drains the backlog through the ASK tickable one batch per tick', function (): void {
    $tick = new EventOutboxTick($this->dispatcher, batchSize: 2);

    $this->recorder->record(outboxEnvelope((string) $this->tenantId));
    $this->recorder->record(outboxEnvelope((string) $this->tenantId, 'access-2'));
    $this->recorder->record(outboxEnvelope((string) $this->tenantId, 'access-3'));

    expect($tick->isIdle())->toBeFalse()
        ->and($tick->queueSize())->toBe(3);

    $tick->tick(0);
    expect($tick->lastDispatched())->toBe(2)
        ->and($tick->queueSize())->toBe(1);

    $tick->tick(0);
    expect($tick->lastDispatched())->toBe(1)
        ->and($tick->isIdle())->toBeTrue()
        ->and($tick->pressure())->toBe(0)
        ->and($tick->name())->toBe('EventOutboxTick');

    $tick->tick(0);
    expect($tick->lastDispatched())->toBe(0);
});
