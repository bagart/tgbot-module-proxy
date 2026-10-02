<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use BAGArt\ProxyOperations\Domain\Cache\EventOrderingPolicy;
use BAGArt\ProxyOperations\Domain\Cache\SequenceNumber;

function envelope(): EventEnvelope
{
    return new EventEnvelope(
        eventId: EventEnvelope::generateId(),
        eventType: 'AccessStateChanged',
        occurredAt: '2026-08-26T12:00:00+00:00',
        tenantId: 'tenant-1',
        aggregateRef: 'access:abc123',
        payload: ['from' => 'testing', 'to' => 'working'],
    );
}

it('generates v4-format unique event ids', function (): void {
    $first = EventEnvelope::generateId();
    $second = EventEnvelope::generateId();

    expect($first)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($first)->not->toBe($second);
});

it('round-trips through JSON', function (): void {
    $event = envelope();

    $restored = EventEnvelope::fromJson(json_decode(json_encode($event), true));

    expect($restored)->toEqual($event);
});

it('carries the schema version in the serialized form', function (): void {
    $data = envelope()->jsonSerialize();

    expect($data['schemaVersion'])->toBe(EventEnvelope::SCHEMA_VERSION);
});

it('rejects unknown schema versions', function (): void {
    $payload = json_decode(json_encode(envelope()), true);
    $payload['schemaVersion'] = 2;

    EventEnvelope::fromJson($payload);
})->throws(RuntimeException::class, 'Unsupported EventEnvelope schemaVersion');

it('rejects malformed event ids', function (): void {
    $payload = json_decode(json_encode(envelope()), true);
    $payload['eventId'] = 'not-a-uuid';

    EventEnvelope::fromJson($payload);
})->throws(RuntimeException::class, 'v4-format UUID');

it('rejects non-ISO-8601 timestamps', function (): void {
    $payload = json_decode(json_encode(envelope()), true);
    $payload['occurredAt'] = 'yesterday maybe';

    EventEnvelope::fromJson($payload);
})->throws(RuntimeException::class, 'ISO-8601');

it('rejects empty event types and tenants during deserialization', function (): void {
    $payload = json_decode(json_encode(envelope()), true);
    $payload['eventType'] = '';

    EventEnvelope::fromJson($payload);
})->throws(RuntimeException::class, 'non-empty');

it('enforces strict per-aggregate ordering', function (): void {
    $policy = new EventOrderingPolicy();

    expect($policy->isNext(null, SequenceNumber::initial()))->toBeTrue()
        ->and($policy->isNext(null, new SequenceNumber(2)))->toBeFalse()
        ->and($policy->isNext(SequenceNumber::initial(), new SequenceNumber(2)))->toBeTrue()
        ->and($policy->isNext(SequenceNumber::initial(), SequenceNumber::initial()))->toBeFalse()
        ->and($policy->isNext(new SequenceNumber(4), new SequenceNumber(6)))->toBeFalse();
});

it('tracks aggregates independently of each other', function (): void {
    $policy = new EventOrderingPolicy();
    $aggregateOneLastSeen = new SequenceNumber(3);
    $aggregateTwoLastSeen = null;

    expect($policy->isNext($aggregateTwoLastSeen, SequenceNumber::initial()))->toBeTrue()
        ->and($policy->isNext($aggregateOneLastSeen, new SequenceNumber(4)))->toBeTrue()
        ->and($policy->isNext($aggregateOneLastSeen, SequenceNumber::initial()))->toBeFalse();
});

it('builds consecutive sequences from the value object', function (): void {
    $sequence = SequenceNumber::initial();

    expect($sequence->next()->value)->toBe(2)
        ->and($sequence->next()->next()->value)->toBe(3);
});

it('rejects non-positive sequence numbers', function (): void {
    new SequenceNumber(0);
})->throws(InvalidArgumentException::class);
