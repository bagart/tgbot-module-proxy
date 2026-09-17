<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Translation helper for proxy operations module.
 * Wraps Laravel's translation layer for proxy-specific keys.
 */
final class ProxyTrans
{
    public static function get(string $key, array $replace = []): string
    {
        return Lang::get($key, $replace);
    }

    public static function error(string $code): string
    {
        return self::get('proxy.errors.'.$code);
    }
}
