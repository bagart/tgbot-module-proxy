<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Cache;

use DateTimeImmutable;
use Exception;
use JsonSerializable;
use RuntimeException;

/**
 * Uniform wrapper for all three event classes — domain, integration,
 * operational (plan §11.20). Readonly, additive-only: new optional payload
 * fields may appear, existing ones never change meaning.
 *
 * Delivery is at-least-once via the Postgres transactional outbox; consumers
 * must be idempotent by eventId and order events per aggregate by
 * SequenceNumber (see EventOrderingPolicy).
 */
final readonly class EventEnvelope implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    private const string UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /**
     * @param  string  $eventId  UUID (v4-format).
     * @param  string  $eventType  Catalog name, e.g. `AccessStateChanged`.
     * @param  string  $occurredAt  ISO-8601 timestamp with timezone.
     * @param  string  $aggregateRef  Per-aggregate ordering scope id.
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $occurredAt,
        public readonly string $tenantId,
        public readonly string $aggregateRef,
        public readonly array $payload,
    ) {}

    /**
     * Random v4-format event id for outbox inserts; no external uuid
     * dependency is used.
     */
    public static function generateId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'eventId' => $this->eventId,
            'eventType' => $this->eventType,
            'occurredAt' => $this->occurredAt,
            'tenantId' => $this->tenantId,
            'aggregateRef' => $this->aggregateRef,
            'payload' => $this->payload,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format or a mandatory field is not recognized.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported EventEnvelope schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If a mandatory field is missing or invalid.
     */
    private static function fromJsonV1(array $data): self
    {
        $eventId = (string) ($data['eventId'] ?? '');

        if (preg_match(self::UUID_V4_PATTERN, $eventId) !== 1) {
            throw new RuntimeException('EventEnvelope eventId must be a v4-format UUID.');
        }

        $envelope = new self(
            eventId: $eventId,
            eventType: (string) ($data['eventType'] ?? ''),
            occurredAt: (string) ($data['occurredAt'] ?? ''),
            tenantId: (string) ($data['tenantId'] ?? ''),
            aggregateRef: (string) ($data['aggregateRef'] ?? ''),
            payload: (array) ($data['payload'] ?? []),
        );

        if ($envelope->eventType === '' || $envelope->tenantId === '') {
            throw new RuntimeException('EventEnvelope requires non-empty eventType and tenantId.');
        }

        try {
            new DateTimeImmutable($envelope->occurredAt);
        } catch (Exception) {
            throw new RuntimeException('EventEnvelope occurredAt must be an ISO-8601 timestamp.');
        }

        return $envelope;
    }
}
