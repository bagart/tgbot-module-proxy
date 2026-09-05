<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use LogicException;

/**
 * Raised on any mutation attempt of a persisted ProxyObservation
 * (plan §11.7 — observations are append-only: no UPDATE, no DELETE).
 */
final class ImmutableRecordException extends LogicException
{
    public static function forColumn(string $record, string $column): self
    {
        return new self("{$record} is immutable: {$column} must not be changed after insert.");
    }
}
