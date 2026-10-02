<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use RuntimeException;

/**
 * Thrown when a placement request references access ids that do not belong to
 * the resolved tenant (INV-006, §11.22 — no tenant leakage). Nothing is
 * persisted when this fires.
 */
final class ForeignAccessIdException extends RuntimeException
{
}
