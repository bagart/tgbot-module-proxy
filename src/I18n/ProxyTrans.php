<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\I18n;

/**
 * Proxy translation helper — resolves i18n keys with fallback to English.
 */
final class ProxyTrans
{
    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    private static ?string $locale = null;

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
        self::$cache = [];
    }

    public static function get(string $key, array $replace = []): string
    {
        $locale = self::$locale ?? config('app.locale', 'en');
        $translations = self::load($locale);
        $fallback = self::load('en');

        $value = $translations[$key] ?? $fallback[$key] ?? $key;

        foreach ($replace as $k => $v) {
            $value = str_replace(":{$k}", (string) $v, $value);
        }

        return $value;
    }

    public static function error(string $code): string
    {
        return self::get("proxy.errors.{$code}");
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $locale): array
    {
        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $path = dirname(__DIR__, 2)."/lang/{$locale}.json";

        if (! is_file($path)) {
            self::$cache[$locale] = [];

            return [];
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        self::$cache[$locale] = is_array($data) ? $data : [];

        return self::$cache[$locale];
    }
}
