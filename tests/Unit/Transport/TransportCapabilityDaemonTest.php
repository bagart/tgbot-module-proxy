<?php

declare(strict_types=1);

use BAGArt\AsyncKernel\ASKShutdownContext;
use BAGArt\AsyncKernel\Contracts\ASKSchedulerContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKDaemonContract;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKShutdownAware;
use BAGArt\AsyncKernel\Contracts\Daemons\ASKWarmableContract;
use BAGArt\AsyncKernel\Contracts\Daemons\WithASKTickableContract;
use BAGArt\AsyncKernel\Enum\ShutdownPhase;
use BAGArt\ProxyOperations\Checker\ExecutionResultNormalizer;
use BAGArt\ProxyOperations\Checker\JudgeProvider;
use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Checker\ProbeOutcomeClassifier;
use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
use BAGArt\ProxyOperations\Tool\ProbeTool;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Tool\ToolCapabilities;
use BAGArt\ProxyOperations\Tool\ToolId;
use BAGArt\ProxyOperations\Tool\ToolLimits;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use BAGArt\ProxyOperations\Tool\ToolSecurity;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Transport\TransportCapabilityDaemon;
use BAGArt\ProxyOperations\Transport\WorkerExecutionPlaneHandler;
use BAGArt\ProxyOperations\Transport\ProbeLeaseRenewer;
use BAGArt\ProxyOperations\Domain\Lease\LeaseRenewerContract;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;
use BAGArt\ProxyOperations\Tests\Fixtures\InMemoryAuditDeliveryQueue;

// --- Test doubles ---

final class W1bOkTool implements ProbeTool
{
    public function capabilities(): ToolCapabilities
    {
        return new ToolCapabilities(
            probeTypes: ProbeType::cases(),
            protocols: ['socks5'],
            inputSchemaVersion: 1,
            outputSchemaVersion: 1,
        );
    }

    public function execute(ProbeExecutionContext $context): ProbeToolResult
    {
        return ProbeToolResult::ok(
            observations: ['latency_ms' => 15.0],
            timingsMs: ['connect' => 15.0],
        );
    }
}

final class W1bFailingTool implements ProbeTool
{
    public function capabilities(): ToolCapabilities
    {
        return new ToolCapabilities(
            probeTypes: ProbeType::cases(),
            protocols: ['socks5'],
            inputSchemaVersion: 1,
            outputSchemaVersion: 1,
        );
    }

    public function execute(ProbeExecutionContext $context): ProbeToolResult
    {
        throw new RuntimeException('probe crashed');
    }
}

/**
 * Minimal scheduler that runs enqueued fibers synchronously on tick().
 * Tracks which fibers have been started so we can control execution timing in tests.
 */
final class W1bScheduler implements ASKSchedulerContract
{
    /** @var list<Fiber> */
    public array $enqueued = [];

    public bool $idle = true;

    public function enqueue(Fiber|Closure $fiber): void
    {
        if ($fiber instanceof Fiber) {
            $this->enqueued[] = $fiber;
            $this->idle = false;
        }
    }

    public function tick(int $systemPressure): void
    {
        foreach ($this->enqueued as $fiber) {
            if ($fiber->isStarted()) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                }
            } else {
                $fiber->start();
            }
        }

        $this->enqueued = array_values(array_filter(
            $this->enqueued,
            static fn (Fiber $f): bool => ! $f->isTerminated(),
        ));
        $this->idle = $this->enqueued === [];
    }

    public function pressure(): int
    {
        return 0;
    }

    public function isIdle(): bool
    {
        return $this->idle;
    }

    public function queueSize(): int
    {
        return count($this->enqueued);
    }
}

// --- Helpers ---

function w1bGovernor(int $maxConcurrentProbes = 4): ResourceGovernor
{
    return new ResourceGovernor(new ResourceGovernorSpec(
        maxConcurrentProbes: $maxConcurrentProbes,
        maxProcesses: 8,
        maxMemoryBytes: 256 * 1024 * 1024,
        maxCpuPercent: 80,
        maxExecutionTimeSeconds: 60,
        maxOutputBytes: 1024 * 1024,
        maxStdinBytes: 4096,
        maxFileDescriptors: 8,
    ));
}

function w1bRegistry(): ToolRegistry
{
    return new ToolRegistry([
        'socks-checker' => new ToolManifest(
            name: new ToolId('socks-checker'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: ProbeType::cases(),
                protocols: ['socks5'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 60, maxOutputBytes: 1024 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        ),
    ]);
}

function w1bExecutor(ProbeTool $tool, ?ResourceGovernor $governor = null): ProbeExecutor
{
    return new ProbeExecutor(
        tools: ['socks-checker' => $tool],
        judgeProvider: new class implements JudgeProvider
        {
            public function select(JudgeSetSnapshot $snapshot, ProbeType $probeType, int $count): array
            {
                return [];
            }
        },
        toolRegistry: w1bRegistry(),
        governor: $governor ?? w1bGovernor(),
        timeoutFactory: new ToolTimeoutFactory,
    );
}

function w1bNormalizer(): ExecutionResultNormalizer
{
    return new ExecutionResultNormalizer(
        taxonomy: new FailureTaxonomy,
        classifier: new ProbeOutcomeClassifier(new FailureTaxonomy),
    );
}

function w1bHandler(?ProbeTool $tool = null, ?ResourceGovernor $governor = null): WorkerExecutionPlaneHandler
{
    return new WorkerExecutionPlaneHandler(
        governor: $governor ?? w1bGovernor(),
        executor: w1bExecutor($tool ?? new W1bOkTool, $governor),
        normalizer: w1bNormalizer(),
        checkerNodeId: 'test-node',
    );
}

function w1bDaemon(
    ?ProbeTool $tool = null,
    ?ResourceGovernor $governor = null,
    ?InMemoryAuditDeliveryQueue $queue = null,
    ?W1bScheduler $scheduler = null,
    ?ProbeLeaseRenewer $leaseRenewer = null,
    int $taskBatchSize = 1,
): TransportCapabilityDaemon {
    return new TransportCapabilityDaemon(
        handler: w1bHandler($tool, $governor),
        queue: $queue ?? new InMemoryAuditDeliveryQueue,
        governor: $governor ?? w1bGovernor(),
        scheduler: $scheduler ?? new W1bScheduler,
        leaseRenewer: $leaseRenewer,
        taskBatchSize: $taskBatchSize,
    );
}

function w1bTask(?string $taskId = null): AuditTaskV1
{
    return new AuditTaskV1(
        job: new JobRef(jobId: 'job-' . ($taskId ?? '1'), attemptId: 'att-' . ($taskId ?? '1'), taskId: 'task-' . ($taskId ?? '1')),
        tenantId: 'tenant-1',
        accessRef: new AccessIdentity(
            endpoint: new EndpointIdentity(host: '198.51.100.1', port: 1080, protocol: ProxyProtocol::Socks5),
            credential: new CredentialFingerprint(str_repeat('a', 64)),
        ),
        sealedCredential: null,
        credentialReference: new CredentialReference(handle: 'handle-1'),
        probes: [
            new ProbeExecutionSpecV1(
                probeType: ProbeType::UdpAssociate,
                profile: ProbeProfile::Standard,
                target: '8.8.8.8:53',
                timeoutMs: 5000,
                maxOutputBytes: 65536,
            ),
        ],
        policySnapshotVersion: 1,
        deadline: '2026-12-31T00:00:00Z',
        maxAttempts: 3,
        accessId: 'access-1',
    );
}

function w1bShutdownContext(): ASKShutdownContext
{
    return new ASKShutdownContext(
        phase: ShutdownPhase::DRAINING,
        forced: false,
        deadline: microtime(true) + 60,
    );
}

// --- Tests: contract compliance ---

it('implements ASKDaemonContract', function (): void {
    expect(w1bDaemon())->toBeInstanceOf(ASKDaemonContract::class);
});

it('implements ASKWarmableContract', function (): void {
    expect(w1bDaemon())->toBeInstanceOf(ASKWarmableContract::class);
});

it('implements WithASKTickableContract', function (): void {
    expect(w1bDaemon())->toBeInstanceOf(WithASKTickableContract::class);
});

it('implements ASKShutdownAware', function (): void {
    expect(w1bDaemon())->toBeInstanceOf(ASKShutdownAware::class);
});

it('name returns TransportCapabilityDaemon', function (): void {
    expect(w1bDaemon()->name())->toBe('TransportCapabilityDaemon');
});

it('warm sets warmed state', function (): void {
    $daemon = w1bDaemon();

    expect($daemon->isWarmed())->toBeFalse();

    $daemon->warm();

    expect($daemon->isWarmed())->toBeTrue();
});

it('startup does not throw', function (): void {
    w1bDaemon()->startup();
    expect(true)->toBeTrue();
});

it('onError increments error counter', function (): void {
    $daemon = w1bDaemon();
    $daemon->onError(new RuntimeException('test'));

    expect($daemon->totalErrors())->toBe(1);
});

// --- Tests: tick execution ---

it('tick consumes task from queue and enqueues fiber to scheduler', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler);

    $daemon->tick(0);

    expect($scheduler->enqueued)->toHaveCount(1)
        ->and($daemon->queueSize())->toBe(1);
});

it('tick processes task end-to-end via scheduler', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler);

    $daemon->tick(0);
    $scheduler->tick(0);

    expect($queue->results)->toHaveCount(1)
        ->and($queue->results[0]->taskId)->toBe('task-1')
        ->and($daemon->totalProcessed())->toBe(1)
        ->and($daemon->queueSize())->toBe(0);
});

it('tick processes multiple tasks in batch', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $queue->enqueue(w1bTask('2'));
    $queue->enqueue(w1bTask('3'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler, taskBatchSize: 5);

    $daemon->tick(0);
    $scheduler->tick(0);

    expect($queue->results)->toHaveCount(3)
        ->and($daemon->totalProcessed())->toBe(3);
});

it('tick does nothing when queue is empty', function (): void {
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(scheduler: $scheduler);

    $daemon->tick(0);

    expect($scheduler->enqueued)->toHaveCount(0)
        ->and($daemon->totalProcessed())->toBe(0);
});

// --- Tests: backpressure ---

it('tick skips when governor at capacity', function (): void {
    $governor = w1bGovernor(maxConcurrentProbes: 1);
    $governor->connectionOpened();
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(governor: $governor, queue: $queue, scheduler: $scheduler);

    $daemon->tick(0);

    expect($scheduler->enqueued)->toHaveCount(0)
        ->and($queue->results)->toHaveCount(0);

    $governor->connectionClosed();
});

it('tick does nothing when shutting down', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler);

    $daemon->prepareShutdown();
    $daemon->tick(0);

    expect($scheduler->enqueued)->toHaveCount(0);
});

// --- Tests: shutdown ---

it('prepareShutdown sets shutting down flag', function (): void {
    $daemon = w1bDaemon();

    expect($daemon->isShuttingDown())->toBeFalse();

    $daemon->prepareShutdown();

    expect($daemon->isShuttingDown())->toBeTrue();
});

it('shutdown returns true when no in-flight tasks', function (): void {
    $daemon = w1bDaemon();
    $daemon->warm();

    $result = $daemon->shutdown(w1bShutdownContext());

    expect($result)->toBeTrue()
        ->and($daemon->isShuttingDown())->toBeTrue();
});

it('shutdown returns false when tasks are in-flight', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler);

    $daemon->tick(0);

    // Fiber is enqueued but not ticked — task is in-flight
    $result = $daemon->shutdown(w1bShutdownContext());

    expect($result)->toBeFalse();

    // Complete the fiber
    $scheduler->tick(0);

    // Now shutdown should succeed
    $result = $daemon->shutdown(w1bShutdownContext());
    expect($result)->toBeTrue();
});

it('shutdown flushes governor', function (): void {
    $governor = w1bGovernor();
    $daemon = w1bDaemon(governor: $governor);
    $daemon->warm();
    $governor->connectionOpened();

    $daemon->shutdown(w1bShutdownContext());

    expect($governor->activeConnections())->toBe(0);
});

// --- Tests: pressure / idle / queueSize ---

it('pressure returns 0 when no in-flight', function (): void {
    $daemon = w1bDaemon();

    expect($daemon->pressure())->toBe(0);
});

it('pressure scales with inflight count', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $queue->enqueue(w1bTask('2'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler, taskBatchSize: 5);

    $daemon->tick(0);

    expect($daemon->queueSize())->toBe(2)
        ->and($daemon->pressure())->toBe(1);

    $scheduler->tick(0);

    expect($daemon->queueSize())->toBe(0)
        ->and($daemon->pressure())->toBe(0);
});

it('isIdle returns true when no work', function (): void {
    $daemon = w1bDaemon();

    expect($daemon->isIdle())->toBeTrue();
});

it('isIdle returns false when tasks in-flight', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler);

    $daemon->tick(0);

    expect($daemon->isIdle())->toBeFalse();
});

it('queueSize tracks inflight + pending count', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $queue->enqueue(w1bTask('2'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler, taskBatchSize: 5);

    expect($daemon->queueSize())->toBe(2);

    $daemon->tick(0);

    expect($daemon->queueSize())->toBe(2);
});

// --- Tests: error handling ---

it('tick processes failed probe as result (no error)', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(tool: new W1bFailingTool, queue: $queue, scheduler: $scheduler);

    $daemon->tick(0);
    $scheduler->tick(0);

    // ProbeExecutor wraps tool failures as ProbeToolResult::failed() —
    // processTask() returns an AuditResultV1, not an exception.
    expect($daemon->totalErrors())->toBe(0)
        ->and($queue->results)->toHaveCount(1)
        ->and($queue->results[0]->status->value)->toBe('failed');
});

// --- Tests: metadata ---

it('tickable returns scheduler as companion', function (): void {
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(scheduler: $scheduler);

    expect($daemon->tickable())->toBe([$scheduler]);
});

it('tickable includes lease renewer when provided', function (): void {
    $scheduler = new W1bScheduler;
    $renewer = new ProbeLeaseRenewer(
        leases: Mockery::mock(LeaseRenewerContract::class),
    );
    $daemon = w1bDaemon(scheduler: $scheduler, leaseRenewer: $renewer);

    expect($daemon->tickable())->toBe([$renewer, $scheduler]);
});

it('shutdown priority is 80', function (): void {
    expect(w1bDaemon()->shutdownPriority())->toBe(80);
});

it('shutdown timeout is 60 seconds', function (): void {
    expect(w1bDaemon()->shutdownTimeout())->toBe(60);
});

// --- Tests: lease renewal ---

it('tick tracks access IDs in lease renewer', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $renewer = new ProbeLeaseRenewer(
        leases: Mockery::mock(LeaseRenewerContract::class),
    );
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler, leaseRenewer: $renewer);

    $daemon->tick(0);

    expect($renewer->queueSize())->toBe(1);
});

it('tick untracks access IDs after fiber completes', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $renewer = new ProbeLeaseRenewer(
        leases: Mockery::mock(LeaseRenewerContract::class),
    );
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler, leaseRenewer: $renewer);

    $daemon->tick(0);
    expect($renewer->queueSize())->toBe(1);

    $scheduler->tick(0);
    expect($renewer->queueSize())->toBe(0);
});

it('tick works without lease renewer', function (): void {
    $queue = new InMemoryAuditDeliveryQueue;
    $queue->enqueue(w1bTask('1'));
    $scheduler = new W1bScheduler;
    $daemon = w1bDaemon(queue: $queue, scheduler: $scheduler);

    $daemon->tick(0);
    $scheduler->tick(0);

    expect($queue->results)->toHaveCount(1);
});
