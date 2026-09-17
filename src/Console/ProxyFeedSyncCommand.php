<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Feed\FeedSyncContract;
use BAGArt\ProxyOperations\Models\ProxyFeedSource;
use Illuminate\Console\Command;

/**
 * Sync proxy feeds from external sources (plan §11.28).
 *
 * Iterates active feed sources, fetches, parses, deduplicates, and imports.
 * Schedule: proxy:feed:sync every 6 hours (configurable per feed).
 */
class ProxyFeedSyncCommand extends Command
{
    protected $signature = 'proxy:feed:sync
                            {--feed-id= : Specific feed source ID to sync (optional)}
                            {--dry-run : Show what would be synced without importing}';

    protected $description = 'Sync proxy feeds from external sources';

    public function __construct(
        private readonly FeedSyncContract $feedSync,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $feedId = $this->option('feed-id');

        if ($feedId !== null) {
            return $this->syncSingle((int) $feedId);
        }

        return $this->syncAll();
    }

    private function syncSingle(int $feedId): int
    {
        $feed = ProxyFeedSource::query()->find($feedId);

        if ($feed === null) {
            $this->error("Feed source #{$feedId} not found.");

            return self::FAILURE;
        }

        $this->info("Syncing feed #{$feedId}: {$feed->url}");

        $result = $this->feedSync->syncFeed($feed);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Imported', $result['imported']],
                ['Skipped', $result['skipped']],
                ['Errors', $result['errors']],
            ],
        );

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function syncAll(): int
    {
        $this->info('Syncing all active feeds...');

        $results = $this->feedSync->syncAll();

        if ($results === []) {
            $this->info('No feeds due for sync.');

            return self::SUCCESS;
        }

        $totalImported = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($results as $result) {
            $totalImported += $result['imported'];
            $totalSkipped += $result['skipped'];
            $totalErrors += $result['errors'];

            $this->line("  Feed #{$result['feed_id']}: imported={$result['imported']}, skipped={$result['skipped']}, errors={$result['errors']}");
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Total'],
            [
                ['Feeds synced', count($results)],
                ['Total imported', $totalImported],
                ['Total skipped', $totalSkipped],
                ['Total errors', $totalErrors],
            ],
        );

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
