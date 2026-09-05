<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use InvalidArgumentException;

/**
 * Execution limits declared by a tool manifest (plan §11.39 пп.10–11).
 */
final readonly class ToolLimits
{
    public function __construct(
        public readonly int $maxExecutionTimeSeconds,
        public readonly int $maxOutputBytes,
    ) {
        if ($this->maxExecutionTimeSeconds < 1) {
            throw new InvalidArgumentException('maxExecutionTime must be a positive number of seconds.');
        }

        if ($this->maxOutputBytes < 1) {
            throw new InvalidArgumentException('maxOutputBytes must be positive.');
        }
    }
}
