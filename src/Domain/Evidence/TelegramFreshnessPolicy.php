<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

/**
 * Configuration DTO for Telegram freshness policy (plan §11.35 п.10).
 * Controls how long a Telegram probe result stays "fresh" before
 * the derived `telegram_usable` flag becomes stale.
 */
final readonly class TelegramFreshnessPolicy
{
    public function __construct(
        public int $freshnessTtlSeconds = 3600,
        public string $evidenceVersion = 'tg-dc:v1',
    ) {
    }

    /**
     * Whether the given check timestamp is still within the freshness window.
     */
    public function isFresh(\DateTimeImmutable $checkedAt, \DateTimeImmutable $now): bool
    {
        return $now->getTimestamp() - $checkedAt->getTimestamp() < $this->freshnessTtlSeconds;
    }

    /**
     * Compute the fresh-until timestamp from a check timestamp.
     */
    public function freshUntil(\DateTimeImmutable $checkedAt): \DateTimeImmutable
    {
        return $checkedAt->modify("+{$this->freshnessTtlSeconds} seconds");
    }
}
