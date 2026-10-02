<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ASKClientRedis\Redis\Contract\RedisClientContract;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use JsonException;
use RuntimeException;

/**
 * Redis Streams delivery queue (plan §11.9, T18 wiring pattern): tasks go to
 * `proxy:audit:tasks`, worker results are drained from `proxy:audit:results`.
 * The injected client adapter connects lazily (INV-009: no client is ever
 * constructed here — the host wiring injects the ASK client contract).
 *
 * Result consumption is cursor-based (at-least-once): each consumer instance
 * advances its own last-id over `proxy:audit:results`; ingestion dedups by
 * task_id + attempt_id (§11.19). Stream trimming is a retention concern and
 * stays out of the transport contract.
 */
final class RedisStreamsAuditDeliveryQueue implements AuditDeliveryQueue
{
    private const string FIELD_PAYLOAD = 'payload';

    private string $tasksCursor = '0';

    private string $resultsCursor = '0';

    public function __construct(
        private readonly RedisClientContract $redis,
        private readonly string $tasksStream = 'proxy:audit:tasks',
        private readonly string $resultsStream = 'proxy:audit:results',
    ) {
    }

    public function enqueue(AuditTaskV1 $task): void
    {
        $this->push($this->tasksStream, $task);
    }

    public function consumeTasks(int $max): array
    {
        if ($max < 1) {
            return [];
        }

        $raw = $this->redis->xRead([$this->tasksStream => $this->tasksCursor], $max, -1);

        if ($raw === false || $raw === null) {
            return [];
        }

        $tasks = [];

        foreach ($raw[$this->tasksStream] ?? [] as $entryId => $fields) {
            $tasks[] = AuditTaskV1::fromJson($this->payload((array) $fields));
            $this->tasksCursor = (string) $entryId;
        }

        return $tasks;
    }

    public function consumeResults(int $max): array
    {
        if ($max < 1) {
            return [];
        }

        // block = -1: no BLOCK option — the drain is non-blocking and
        // returns whatever is available up to $max.
        $raw = $this->redis->xRead([$this->resultsStream => $this->resultsCursor], $max, -1);

        if ($raw === false || $raw === null) {
            return [];
        }

        $results = [];

        foreach ($raw[$this->resultsStream] ?? [] as $entryId => $fields) {
            $results[] = AuditResultV1::fromJson($this->payload((array) $fields));
            $this->resultsCursor = (string) $entryId;
        }

        return $results;
    }

    public function enqueueResult(AuditResultV1 $result): void
    {
        $this->push($this->resultsStream, $result);
    }

    public function pendingCount(): int
    {
        try {
            $len = $this->redis->xLen($this->tasksStream);

            return is_int($len) ? $len : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function push(string $stream, AuditTaskV1|AuditResultV1 $message): void
    {
        try {
            $payload = json_encode($message, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The audit wire message could not be serialized.', 0, $exception);
        }

        $entryId = $this->redis->xAdd($stream, '*', [self::FIELD_PAYLOAD => $payload]);

        if ($entryId === false) {
            throw new RuntimeException(sprintf('The audit message could not be appended to the "%s" stream.', $stream));
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function payload(array $fields): array
    {
        $raw = (string) ($fields[self::FIELD_PAYLOAD] ?? '');

        try {
            return (array) json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The audit stream payload is not valid JSON.', 0, $exception);
        }
    }
}
