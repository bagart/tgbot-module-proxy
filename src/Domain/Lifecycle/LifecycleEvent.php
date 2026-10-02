<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lifecycle;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use DateTimeImmutable;
use JsonSerializable;
use RuntimeException;

/**
 * Immutable record of one access lifecycle transition (plan §11.6).
 *
 * Emitted by AccessStateMachine; consumed by the domain event pipeline
 * (AccessStateChanged envelope, plan §11.9) and persisted as lifecycle history.
 */
final readonly class LifecycleEvent implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  FailureCode|null  $reason  Concrete failing code when the transition
     *                                    was driven by a failure; null for health-signal causes.
     * @param  DateTimeImmutable  $occurredAt  Transition timestamp.
     */
    public function __construct(
        public AccessState $from,
        public AccessState $to,
        public ?FailureCode $reason,
        public DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from->value,
            'to' => $this->to->value,
            'reason' => $this->reason?->value,
            'occurredAt' => $this->occurredAt->format(DateTimeImmutable::ATOM),
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException If the format version is not recognized.
     */
    public static function fromJson(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? 1;

        return match ($schemaVersion) {
            1 => self::fromJsonV1($data),
            default => throw new RuntimeException("Unsupported LifecycleEvent schemaVersion: {$schemaVersion}"),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            from: AccessState::from((string) $data['from']),
            to: AccessState::from((string) $data['to']),
            reason: isset($data['reason']) ? FailureCode::from((string) $data['reason']) : null,
            occurredAt: new DateTimeImmutable((string) $data['occurredAt']),
        );
    }
}
