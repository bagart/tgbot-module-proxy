<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use InvalidArgumentException;

/**
 * Event catalog (plan §11.20): maps event type → schema_version, event class
 * (domain / integration / operational) and the aggregate type that scopes its
 * ordering. Readonly DTO built from `config/proxy-operations.php` →
 * `audit.events.registry`; the recorder resolves schema_version from it and
 * fails closed on unknown types.
 */
final readonly class EventTypeRegistry
{
    public const string CLASS_DOMAIN = 'domain';

    public const string CLASS_INTEGRATION = 'integration';

    public const string CLASS_OPERATIONAL = 'operational';

    /**
     * @param  array<non-empty-string, array{class: string, aggregate_type: non-empty-string, schema_version: int}>  $definitions  Event type name → definition.
     */
    public function __construct(
        private array $definitions = [],
    ) {}

    /**
     * Builds the registry from the `audit.events` config block.
     *
     * @param  array<string, mixed>  $config  `proxy-operations.audit.events`.
     */
    public static function fromConfig(array $config): self
    {
        $definitions = [];

        foreach ((array) ($config['registry'] ?? []) as $entry) {
            $type = (string) ($entry['type'] ?? '');

            if ($type === '') {
                throw new InvalidArgumentException('Event registry entries require a non-empty type.');
            }

            $definitions[$type] = [
                'class' => (string) ($entry['class'] ?? self::CLASS_DOMAIN),
                'aggregate_type' => (string) ($entry['aggregate_type'] ?? ''),
                'schema_version' => (int) ($entry['schema_version'] ?? 1),
            ];
        }

        return new self($definitions);
    }

    public function has(string $eventType): bool
    {
        return isset($this->definitions[$eventType]);
    }

    /**
     * @throws InvalidArgumentException When the event type is not cataloged.
     */
    public function schemaVersion(string $eventType): int
    {
        return $this->definition($eventType)['schema_version'];
    }

    /**
     * @return string One of the CLASS_* constants.
     *
     * @throws InvalidArgumentException When the event type is not cataloged.
     */
    public function eventClass(string $eventType): string
    {
        return $this->definition($eventType)['class'];
    }

    /**
     * @throws InvalidArgumentException When the event type is not cataloged.
     */
    public function aggregateType(string $eventType): string
    {
        return $this->definition($eventType)['aggregate_type'];
    }

    /**
     * @return array{class: string, aggregate_type: non-empty-string, schema_version: int}
     *
     * @throws InvalidArgumentException When the event type is not cataloged.
     */
    private function definition(string $eventType): array
    {
        return $this->definitions[$eventType]
            ?? throw new InvalidArgumentException("Unknown event type: {$eventType}.");
    }
}
