<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot;

/**
 * Contract for individual bot command handlers (plan §11.10).
 */
interface BotCommandHandler
{
    public function handles(): string;

    public function handle(BotCommandContext $context): array;
}
