<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Failure;

/**
 * Attributes governing retry, health, capability and quarantine handling for a
 * failure code (plan §11.16). All flags are booleans by design; contextual
 * columns of the plan table are resolved conservatively: TARGET_* health
 * influence is soft but real (affectsHealth=true) and never counts against the
 * proxy (countsAsFailure=false).
 */
final readonly class FailureDescriptor
{
    public function __construct(
        public FailureCode $code,
        public FailureClass $class,
        public bool $retryable,
        public bool $countsAsFailure,
        public bool $affectsHealth,
        public bool $affectsCapability,
        public bool $quarantineAfterThreshold,
    ) {}
}
