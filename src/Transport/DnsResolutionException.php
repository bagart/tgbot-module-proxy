<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use RuntimeException;

/**
 * Thrown when DNS resolution fails through any resolver mode.
 */
final class DnsResolutionException extends RuntimeException {}
