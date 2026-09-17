<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\AsyncKernel\ASKClock;
use BAGArt\AsyncKernel\AsyncKernel;
use BAGArt\AsyncKernel\Drivers\ASKFiberScheduler;
use BAGArt\AsyncKernel\Wrappers\ASKLogWrapper;
use BAGArt\ProxyOperations\Audit\LeaseService;
use BAGArt\ProxyOperations\Audit\RedisStreamsAuditDeliveryQueue;
use BAGArt\ProxyOperations\Transport\ProbeLeaseRenewer;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Transport\TransportCapabilityDaemon;
use BAGArt\ProxyOperations\Transport\WorkerExecutionPlaneHandler;
use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use Illuminate\Console\Command;

/**
 * Start the proxy audit worker daemon (W2).
 *
 * Consumes audit tasks from Redis Streams, dispatches probe execution via
 * Fiber-based async I/O, and pushes results back. Runs as an ASK daemon
 * with graceful shutdown on SIGTERM/SIGINT.
 */
class ProxyWorkerCommand extends Command
{
    protected $signature = 'proxy:worker
                            {--concurrent=4 : Max concurrent probe connections}
                            {--batch=1 : Tasks to consume per tick}
                            {--memory-limit=512M : PHP memory limit}
                            {--debug : Enable debug logging}';

    protected $description = 'Start the proxy audit worker daemon';

    public function handle(
        ASKLogWrapper $logger,
    ): int {
        $this->configureEnvironment();

        $this->line('Starting proxy worker daemon...');

        $daemon = $this->resolveDaemon($logger);

        $kernel = new AsyncKernel(logger: $logger);
        $kernel->addDaemon($daemon);

        $this->info('Worker daemon registered. Entering tick loop...');
        $this->info('Press Ctrl+C to initiate graceful shutdown.');

        $kernel->run();

        $this->info('Worker daemon stopped.');

        return self::SUCCESS;
    }

    private function resolveDaemon(ASKLogWrapper $logger): TransportCapabilityDaemon
    {
        $maxConcurrent = (int) $this->option('concurrent');
        $batchSize = (int) $this->option('batch');

        $governor = new ResourceGovernor(new ResourceGovernorSpec(
            maxConcurrentProbes: $maxConcurrent,
            maxProcesses: $maxConcurrent * 2,
            maxMemoryBytes: 256 * 1024 * 1024,
            maxCpuPercent: 80,
            maxExecutionTimeSeconds: 60,
            maxOutputBytes: 1024 * 1024,
            maxStdinBytes: 4096,
            maxFileDescriptors: 8,
        ));

        /** @var WorkerExecutionPlaneHandler $handler */
        $handler = app(WorkerExecutionPlaneHandler::class);

        /** @var RedisStreamsAuditDeliveryQueue $queue */
        $queue = app(RedisStreamsAuditDeliveryQueue::class);

        $scheduler = new ASKFiberScheduler;

        $leaseRenewer = null;
        if (app()->bound(LeaseService::class)) {
            $leaseRenewer = new ProbeLeaseRenewer(
                leases: app(LeaseService::class),
            );
        }

        return new TransportCapabilityDaemon(
            handler: $handler,
            queue: $queue,
            governor: $governor,
            scheduler: $scheduler,
            leaseRenewer: $leaseRenewer,
            logger: $logger,
            taskBatchSize: $batchSize,
            pressureQueueCapacity: 256,
        );
    }

    private function configureEnvironment(): void
    {
        $memoryLimit = (string) $this->option('memory-limit');

        ini_set('memory_limit', $memoryLimit);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        if ($this->option('debug')) {
            $this->line('Debug mode enabled');
        }
    }
}
