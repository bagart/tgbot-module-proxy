<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Feed;

use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportSource;
use BAGArt\ProxyOperations\Domain\Parsing\ProxyListParser;
use BAGArt\ProxyOperations\Models\FeedFormat;
use BAGArt\ProxyOperations\Models\FeedSourceStatus;
use BAGArt\ProxyOperations\Models\ProxyFeedSource;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Feed sync service (plan §11.28, §§11.35 п.16–17):
 * fetch URL → parse → scope guard → dedup → import via ImportProxiesCommand.
 *
 * Idempotent: same feed URL + same content → no duplicate imports.
 * Tenant-scoped: all operations respect tenant isolation.
 */
final class FeedSyncService implements FeedSyncContract
{
    private const int HTTP_TIMEOUT_SECONDS = 30;
    private const int MAX_RETRIES = 2;
    private const int LOCK_TTL_SECONDS = 300;

    public function __construct(
        private readonly ProxyListParser $parser,
        private readonly TenantContext $tenant,
    ) {
    }

    public function syncFeed(ProxyFeedSource $feed): array
    {
        $lockKey = "proxy:feed:sync:{$feed->id}";

        $locked = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);

        if (! $locked->get()) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
        }

        try {
            $rawContent = $this->fetchFeed($feed);

            if ($rawContent === null) {
                $this->markError($feed, 'Failed to fetch feed URL');

                return ['imported' => 0, 'skipped' => 0, 'errors' => 1];
            }

            $entries = $this->parseFeed($rawContent, $feed->format);

            if ($entries === []) {
                $this->markSynced($feed);

                return ['imported' => 0, 'skipped' => 0, 'errors' => 0];
            }

            $importResult = $this->importEntries($entries, $feed);

            $this->markSynced($feed);

            Log::info('proxy.feed.sync.completed', [
                'feed_id' => $feed->id,
                'tenant_id' => $feed->tenant_id,
                'imported' => $importResult['imported'],
                'skipped' => $importResult['skipped'],
                'errors' => $importResult['errors'],
            ]);

            return $importResult;
        } catch (Throwable $e) {
            Log::error('proxy.feed.sync.exception', [
                'feed_id' => $feed->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $this->markError($feed, $e->getMessage());

            return ['imported' => 0, 'skipped' => 0, 'errors' => 1];
        } finally {
            $locked->release();
        }
    }

    public function syncAll(): array
    {
        $results = [];
        $feeds = ProxyFeedSource::query()
            ->where('status', FeedSourceStatus::Active->value)
            ->get();

        foreach ($feeds as $feed) {
            /** @var ProxyFeedSource $feed */
            if ($feed->isDueForSync()) {
                $results[] = array_merge(
                    ['feed_id' => $feed->id],
                    $this->syncFeed($feed),
                );
            }
        }

        return $results;
    }

    public function status(ProxyFeedSource $feed): FeedSyncStatus
    {
        return new FeedSyncStatus(
            feedId: $feed->id,
            url: $feed->url,
            imported: 0,
            skipped: 0,
            errors: $feed->status === FeedSourceStatus::Error ? 1 : 0,
        );
    }

    private function fetchFeed(ProxyFeedSource $feed): ?string
    {
        $url = $feed->url;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->withHeaders(['User-Agent' => 'ProxyFeedSync/1.0'])
                    ->retry(self::MAX_RETRIES, 1000)
                    ->get($url);

                if ($response->successful()) {
                    return $response->body();
                }

                Log::warning('proxy.feed.fetch.failed', [
                    'feed_id' => $feed->id,
                    'url' => $url,
                    'status' => $response->status(),
                    'attempt' => $attempt,
                ]);
            } catch (Throwable $e) {
                Log::warning('proxy.feed.fetch.exception', [
                    'feed_id' => $feed->id,
                    'url' => $url,
                    'attempt' => $attempt,
                    'exception' => $e::class,
                ]);
            }
        }

        return null;
    }

    /**
     * @return list<array{endpoint: string, credentials: string}>
     */
    private function parseFeed(string $rawContent, FeedFormat $format): array
    {
        $formatValue = $format->value;

        return match ($formatValue) {
            'text_line' => $this->parser->parseLineFormat($rawContent),
            'json_array' => $this->parser->parseJsonFormat($rawContent),
            default => $this->parser->parseLineFormat($rawContent),
        };
    }

    /**
     * @param  list<array{endpoint: string, credentials: string}>  $entries
     */
    private function importEntries(array $entries, ProxyFeedSource $feed): array
    {
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($entries as $entry) {
            try {
                $command = new ImportProxiesCommand(
                    rawLines: [$entry['endpoint']],
                    tenantId: (string) $feed->tenant_id,
                    source: ImportSource::Feed,
                );

                $result = app(\BAGArt\ProxyOperations\Application\ImportProxiesHandler::class)->handle($command);

                if ($result->imported > 0) {
                    $imported += $result->imported;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $errors++;

                Log::warning('proxy.feed.import.entry_failed', [
                    'feed_id' => $feed->id,
                    'exception' => $e::class,
                ]);
            }
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function markSynced(ProxyFeedSource $feed): void
    {
        $feed->update([
            'last_synced_at' => now(),
            'status' => FeedSourceStatus::Active->value,
        ]);
    }

    private function markError(ProxyFeedSource $feed, string $error): void
    {
        $feed->update([
            'status' => FeedSourceStatus::Error->value,
        ]);

        Log::error('proxy.feed.sync.error', [
            'feed_id' => $feed->id,
            'url' => $feed->url,
            'error' => $error,
        ]);
    }
}
