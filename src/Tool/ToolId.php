<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use InvalidArgumentException;

/**
 * Registry-safe tool identifier (plan §11.39 п.10): a lowercase slug that is
 * the ONLY execution selector accepted from callers. Executable paths never
 * cross this boundary (INV-012) — the registry resolves the slug internally.
 */
final readonly class ToolId
{
    private const int MAX_LENGTH = 64;

    public function __construct(
        public readonly string $value,
    ) {
        if ($this->value === ''
            || strlen($this->value) > self::MAX_LENGTH
            || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', $this->value) !== 1
        ) {
            throw new InvalidArgumentException('ToolId must be a lowercase slug of at most '.self::MAX_LENGTH.' characters.');
        }
    }
}
