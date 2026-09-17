<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot\Wizard;

use Illuminate\Support\Facades\Cache;

/**
 * Redis-backed wizard session store (plan §§10.12 п.11).
 * TTL-based automatic expiry (30 minutes default).
 */
final class RedisWizardSessionStore implements WizardSessionStore
{
    private const string KEY_PREFIX = 'proxy:wizard:';

    public function get(int $userId): ?WizardSession
    {
        $data = Cache::get(self::KEY_PREFIX . $userId);

        if ($data === null) {
            return null;
        }

        $session = WizardSession::fromJson($data);

        if ($session->isExpired()) {
            $this->delete($userId);

            return null;
        }

        return $session;
    }

    public function save(WizardSession $session): void
    {
        $ttlSeconds = WizardSession::TTL_MINUTES * 60;

        Cache::put(
            self::KEY_PREFIX . $session->userId,
            $session->jsonSerialize(),
            $ttlSeconds,
        );
    }

    public function delete(int $userId): void
    {
        Cache::forget(self::KEY_PREFIX . $userId);
    }

    public function hasActive(int $userId): bool
    {
        return $this->get($userId) !== null;
    }
}
