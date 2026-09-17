<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Feed;

use BAGArt\ProxyOperations\Models\ProxyFeedSource;

/**
 * Contract for syncing proxy feeds from external sources (plan §11.28).
 */
interface FeedSyncContract
{
    /**
     * Sync a single feed source — fetch, parse, dedup, import.
     *
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function syncFeed(ProxyFeedSource $feed): array;

    /**
     * Sync all active feeds that are due for sync.
     *
     * @return array<int, array{feed_id: int, imported: int, skipped: int, errors: int}>
     */
    public function syncAll(): array;

    /**
     * Get sync status for a feed source.
     */
    public function status(ProxyFeedSource $feed): FeedSyncStatus;
}
