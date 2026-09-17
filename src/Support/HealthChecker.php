<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Infrastructure health checks via Laravel facades (Cache, DB).
 * No direct Redis client types — INV-009 compliant.
 */
final class HealthChecker implements HealthCheckerContract
{
    public function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function checkRedis(): bool
    {
        try {
            Cache::get('health_check_ping');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
