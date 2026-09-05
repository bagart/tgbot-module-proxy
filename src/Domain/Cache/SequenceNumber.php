<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Cache;

use InvalidArgumentException;

/**
 * Monotonic per-aggregate sequence position for event ordering (plan §11.20).
 * Sequences start at 1 and grow without gaps within one aggregate; the
 * aggregate scope itself is carried by EventEnvelope::$aggregateRef.
 */
final readonly class SequenceNumber
{
    public function __construct(
        public readonly int $value,
    ) {
        if ($value < 1) {
            throw new InvalidArgumentException('SequenceNumber must be a positive integer.');
        }
    }

    public static function initial(): self
    {
        return new self(1);
    }

    public function next(): self
    {
        return new self($this->value + 1);
    }
}
