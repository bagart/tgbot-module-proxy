<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Web;

/**
 * Resolves the content-hashed chunk filename minted by the chunk build.
 * UiEntry::Chunk(url) references the hashed name verbatim.
 */
final class ChunkAsset
{
    public const URL_BASE = '/vendor/menu-modules/proxy/';

    /**
     * @throws \RuntimeException when the build manifest is missing or malformed
     */
    public static function file(): string
    {
        $raw = file_get_contents(dirname(__DIR__, 2).'/resources/chunk/build-manifest.json');

        if ($raw === false) {
            throw new \RuntimeException('proxy chunk build manifest missing -- run `npm run build` in the proxy module');
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || ! is_string($decoded['file'] ?? null)) {
            throw new \RuntimeException('proxy chunk build manifest is malformed');
        }

        if (! preg_match('/^app\.[A-Za-z0-9_-]+\.js$/', $decoded['file']) || ! is_file(dirname(__DIR__, 2).'/public/vendor/menu-modules/proxy/'.$decoded['file'])) {
            throw new \RuntimeException("proxy chunk asset '{$decoded['file']}' is not present in the package public dir");
        }

        return $decoded['file'];
    }

    public static function url(): string
    {
        return self::URL_BASE.self::file();
    }
}
