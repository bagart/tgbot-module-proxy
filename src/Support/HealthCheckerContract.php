<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Support;

/**
 * Infrastructure health checks (DB, Redis) abstracted behind a contract
 * to keep Redis client types out of src (INV-009).
 */
interface HealthCheckerContract
{
    public function checkDatabase(): bool;

    public function checkRedis(): bool;
}
