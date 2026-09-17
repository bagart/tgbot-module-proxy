<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Workspace settings query (plan §11.10, §11.29, #83).
 */
final readonly class WorkspaceSettingsQuery implements ApplicationQuery
{
    public function __construct(
        public string $tenantId,
    ) {}
}
