<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;

/**
 * Result of one ResultIngestionService::ingest() call (plan §11.9): a
 * duplicate marker with zero writes, or the observation id plus the lifecycle
 * transition the health evaluation produced (null when the state did not
 * change). Post-commit projections are out of scope here (dispatcher, T28).
 */
final readonly class IngestionOutcome
{
    private function __construct(
        public readonly bool $duplicate,
        public readonly ?string $observationId,
        public readonly ?AccessState $stateTransition,
    ) {
    }

    public static function duplicate(): self
    {
        return new self(duplicate: true, observationId: null, stateTransition: null);
    }

    public static function ingested(?string $observationId, ?AccessState $stateTransition): self
    {
        return new self(duplicate: false, observationId: $observationId, stateTransition: $stateTransition);
    }
}
