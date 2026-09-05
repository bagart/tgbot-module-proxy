<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use RuntimeException;

/**
 * Raised when a caller asks the registry for a tool outside the allowlist
 * (plan §11.39 п.10). Arbitrary execution never happens — there is no fallback
 * path and no executable string involved (INV-012).
 */
final class UnknownToolException extends RuntimeException {}
