<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Feed;

/**
 * Status of a feed sync operation (plan §11.28).
 */
final readonly class FeedSyncStatus
{
    public function __construct(
        public readonly int $feedId,
        public readonly string $url,
        public readonly int $imported,
        public readonly int $skipped,
        public readonly int $errors,
        public readonly ?string $lastError = null,
    ) {}

    public static function success(int $feedId, string $url, int $imported, int $skipped): self
    {
        return new self(feedId: $feedId, url: $url, imported: $imported, skipped: $skipped, errors: 0);
    }

    public static function error(int $feedId, string $url, string $error): self
    {
        return new self(feedId: $feedId, url: $url, imported: 0, skipped: 0, errors: 1, lastError: $error);
    }
}
