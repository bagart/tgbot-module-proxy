<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot;

/**
 * Bot command context — extracted from authenticated Telegram update (plan §11.10).
 */
final readonly class BotCommandContext
{
    public function __construct(
        public string $tenantId,
        public string $chatId,
        public string $userId,
        public string $command,
        public string $arguments,
        public string $locale = 'en',
    ) {
    }
}
