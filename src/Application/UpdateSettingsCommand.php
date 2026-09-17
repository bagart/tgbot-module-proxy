<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Update settings command (plan §11.10, §11.29, #83).
 */
final readonly class UpdateSettingsCommand implements ApplicationCommand
{
    public function __construct(
        public string $tenantId,
        public string $field,
        public mixed $value,
        public ?string $updatedBy = null,
    ) {}
}
