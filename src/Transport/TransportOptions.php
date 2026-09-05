<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

/**
 * Abstract base for protocol-specific transport options.
 * Concrete subclasses carry the configuration each protocol family needs.
 */
abstract readonly class TransportOptions {}
