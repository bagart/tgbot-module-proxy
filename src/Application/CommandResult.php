<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Standard result DTO for application commands.
 */
final readonly class CommandResult
{
    public function __construct(
        public bool $success,
        public ?string $messageKey = null,
        public ?array $data = null,
        public ?string $errorKey = null,
    ) {
    }

    public static function ok(string $messageKey, ?array $data = null): self
    {
        return new self(success: true, messageKey: $messageKey, data: $data);
    }

    public static function fail(string $errorKey): self
    {
        return new self(success: false, errorKey: $errorKey);
    }
}
