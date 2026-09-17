<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot\Wizard;

/**
 * Storage for wizard sessions (plan §§10.12 п.11).
 * Redis-backed with TTL for automatic expiry.
 */
interface WizardSessionStore
{
    public function get(int $userId): ?WizardSession;

    public function save(WizardSession $session): void;

    public function delete(int $userId): void;

    public function hasActive(int $userId): bool;
}
