<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Failure;

/**
 * A proxy-side failure (classes Proxy, Target, Judge, Policy): the endpoint,
 * target or policy is responsible. Never produced for checker/platform faults.
 */
final readonly class ProxyFailure implements Failure
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public FailureDescriptor $descriptor,
        public array $context = [],
    ) {}

    public function descriptor(): FailureDescriptor
    {
        return $this->descriptor;
    }

    public function context(): array
    {
        return $this->context;
    }
}
