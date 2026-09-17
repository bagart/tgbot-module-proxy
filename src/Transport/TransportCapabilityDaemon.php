<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\ASKSchedulerContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKShutdownAware;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKTickableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKWarmableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\ProxyOperations\Audit\AuditDeliveryQueue;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\AsyncKernel\Exceptions\ASKInterruptException;
use Fiber;
use Throwable;

/**
 * Daemon that consumes audit tasks from Redis Streams, dispatches them to
 * the checker worker via WorkerExecutionPlaneHandler, and pushes results
 * back to the results stream (plan §11.30: Scheduler → Audit Worker →
 * Probe Runner).
 *
 * Runs as an ASK daemon in the platform PHP container, using Fiber-based
 * async I/O via the shared ASKSchedulerContract.
 *
 * Lifecycle: ASKWarmableContract (lazy Redis) → tick loop → ASKShutdownAware
 * (drain in-flight before exit).
 */
final class TransportCapabilityDaemon implements ASKDaemonContract, ASKWarmableContract, WithASKTickableContract, ASKShutdownAware
{
    private bool $isShuttingDown = false;

    private bool $warmed = false;

    /** @var array<string, array{task: AuditTaskV1, startedAt: float}> */
    private array $inflight = [];

    private int $totalProcessed = 0;

    private int $totalErrors = 0;

    public function __construct(
        private readonly WorkerExecutionPlaneHandler $handler,
        private readonly AuditDeliveryQueue $queue,
        private readonly ResourceGovernor $governor,
        private readonly ASKSchedulerContract $scheduler,
        private readonly ?ProbeLeaseRenewer $leaseRenewer = null,
        private readonly ?ASKLogWrapper $logger = null,
        private readonly int $taskBatchSize = 1,
        private readonly int $pressureQueueCapacity = 256,
    ) {}

    public function warm(): void
    {
        $this->warmed = true;
        $this->governor->flush();
    }

    public function startup(): void
    {
        $this->logger?->info('[TransportCapabilityDaemon] started');
    }

    public function shutdown(ASKShutdownContext $context): bool
    {
        if (! $this->isShuttingDown) {
            $this->isShuttingDown = true;

            $this->logger?->debug('[TransportCapabilityDaemon::shutdown] need to complete:', [
                'count' => count($this->inflight),
            ]);
        }

        if ($this->inflight === []) {
            $this->governor->flush();

            return true;
        }

        $this->logger?->debug('[TransportCapabilityDaemon::shutdown] waiting for in-flight tasks', [
            'count' => count($this->inflight),
        ]);

        return false;
    }

    public function onError(Throwable $e): void
    {
        $this->totalErrors++;

        $this->logger?->error("[TransportCapabilityDaemon] {$e->getMessage()}", [
            'exception' => $e::class,
        ]);
    }

    public function name(): string
    {
        return 'TransportCapabilityDaemon';
    }

    public function tick(int $systemPressure): void
    {
        if ($this->isShuttingDown) {
            return;
        }

        if (! $this->governor->canOpenConnection()) {
            return;
        }

        $tasks = $this->queue->consumeTasks($this->taskBatchSize);

        foreach ($tasks as $task) {
            $deliveryId = $task->job->taskId . ':' . $task->job->attemptId;
            $this->inflight[$deliveryId] = [
                'task' => $task,
                'startedAt' => microtime(true),
            ];

            $this->leaseRenewer?->track($task->accessId);

            $fiber = new Fiber(function () use ($task, $deliveryId): void {
                try {
                    $payload = ['task' => $task->jsonSerialize()];
                    $result = $this->handler->processTask($payload);
                    $this->queue->enqueueResult($result);
                    $this->totalProcessed++;
                } catch (ASKInterruptException $e) {
                    throw $e; // always bubbles — kernel handles shutdown
                } catch (Throwable $e) {
                    $this->onError($e);
                } finally {
                    $this->leaseRenewer?->untrack($task->accessId);
                    unset($this->inflight[$deliveryId]);
                }
            });

            $this->scheduler->enqueue($fiber);
        }
    }

    public function tickable(): array
    {
        $tickables = [];

        if ($this->leaseRenewer !== null) {
            $tickables[] = $this->leaseRenewer;
        }

        $tickables[] = $this->scheduler;

        return $tickables;
    }

    public function pressure(): int
    {
        $size = $this->queueSize();

        if ($size === 0) {
            return 0;
        }

        return (int) round(($size / $this->pressureQueueCapacity) * 100);
    }

    public function isIdle(): bool
    {
        return $this->inflight === []
            && $this->queue->pendingCount() === 0
            && $this->scheduler->isIdle();
    }

    public function queueSize(): int
    {
        return count($this->inflight) + $this->queue->pendingCount();
    }

    public function isShuttingDown(): bool
    {
        return $this->isShuttingDown;
    }

    public function isWarmed(): bool
    {
        return $this->warmed;
    }

    public function totalProcessed(): int
    {
        return $this->totalProcessed;
    }

    public function totalErrors(): int
    {
        return $this->totalErrors;
    }

    public function shutdownPriority(): int
    {
        return 80;
    }

    public function shutdownTimeout(): int
    {
        return 60;
    }

    public function prepareShutdown(): void
    {
        $this->isShuttingDown = true;

        $this->logger?->debug('[TransportCapabilityDaemon] prepareShutdown: stopping ingestion, draining in-flight', [
            'count' => count($this->inflight),
        ]);
    }
}
