<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Auth;

use BAGArt\TelegramBotManagement\Services\TelegramIdentityService;

/**
 * Workspace resolver — user_id → workspace_id (1:1, lazy-create).
 *
 * Provisioning goes through the platform identity service (D7/D9): no
 * forced uuid id, no fabricated email/password (both nullable since
 * 2026_09_25_000002), race-safe against the unique telegram_id index.
 */
final class WorkspaceResolver
{
    public function __construct(
        private readonly TelegramIdentityService $identity,
    ) {
    }

    public function resolve(int $telegramUserId): string
    {
        return (string) $this->identity->provision($telegramUserId, 'tg_'.$telegramUserId);
    }
}
