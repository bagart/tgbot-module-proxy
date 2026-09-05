<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use InvalidArgumentException;

/**
 * The probe itself, minimal per plan §11.39 п.5: type and target only — the
 * external tool never sees AuditTask or any domain entity (INV-011).
 */
final readonly class ProbeSpec
{
    public function __construct(
        public readonly ProbeType $probeType,
        public readonly string $target,
    ) {
        if ($this->target === '') {
            throw new InvalidArgumentException('Probe target must not be empty.');
        }
    }
}
