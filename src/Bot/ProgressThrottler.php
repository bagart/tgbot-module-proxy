<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot;

/**
 * Throttles bot responses to avoid Telegram 429 rate limits.
 * Tracks last send time per chat and inserts minimal delays when needed.
 */
final class ProgressThrottler
{
    /** @var array<int, float> chat_id => last_send_timestamp */
    private array $lastSendTimes = [];

    private const MIN_INTERVAL_MS = 50;

    /**
     * Returns delay in milliseconds needed before next message to this chat.
     * Returns 0 if no delay is needed.
     */
    public function delayNeeded(int $chatId): int
    {
        $now = microtime(true);
        $last = $this->lastSendTimes[$chatId] ?? 0.0;
        $elapsed = ($now - $last) * 1000;

        if ($elapsed >= self::MIN_INTERVAL_MS) {
            return 0;
        }

        return (int) ceil(self::MIN_INTERVAL_MS - $elapsed);
    }

    /**
     * Records that a message was sent to this chat at the current time.
     */
    public function recordSend(int $chatId): void
    {
        $this->lastSendTimes[$chatId] = microtime(true);
    }

    /**
     * Clears tracking for a chat (e.g. on session end).
     */
    public function clear(int $chatId): void
    {
        unset($this->lastSendTimes[$chatId]);
    }
}
