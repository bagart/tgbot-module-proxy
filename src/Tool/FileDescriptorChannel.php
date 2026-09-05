<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use InvalidArgumentException;

/**
 * Secret delivered over a dedicated Unix file descriptor for binaries that
 * cannot read stdin (plan §11.39 п.7). The descriptor is opened by the runner
 * with hard permissions and closed/zeroized after execution.
 */
final readonly class FileDescriptorChannel implements CredentialChannel
{
    public function __construct(
        public readonly int $fileDescriptor,
    ) {
        if ($this->fileDescriptor < 0) {
            throw new InvalidArgumentException('File descriptor must be >= 0.');
        }
    }

    public function mode(): CredentialDeliveryMode
    {
        return CredentialDeliveryMode::FileDescriptor;
    }
}
