<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\ExecutionResultNormalizer;
use BAGArt\ProxyOperations\Checker\JudgeProvider;
use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Checker\ProbeOutcomeClassifier;
use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Tool\ExecutionPlaneRoute;
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
use BAGArt\ProxyOperations\Transport\WorkerExecutionPlaneHandler;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

// --- Test doubles ---

/**
 * ProbeTool test double: returns a successful result.
 */
final class FakeOkProbeTool implements ProbeTool
{
    /** @var list<ProbeExecutionContext> */
    public array $receivedContexts = [];

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
        $this->receivedContexts[] = $context;

        return ProbeToolResult::ok(
            observations: ['latency_ms' => 15.0],
            timingsMs: ['connect' => 15.0],
        );
    }
}

// --- Helpers ---

function w1aGovernor(int $maxConcurrentProbes = 4): ResourceGovernor
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

function w1aRegistry(): ToolRegistry
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

function w1aExecutor(ProbeTool $tool, ?ResourceGovernor $governor = null): ProbeExecutor
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
        toolRegistry: w1aRegistry(),
        governor: $governor ?? w1aGovernor(),
        timeoutFactory: new ToolTimeoutFactory,
    );
}

function w1aNormalizer(): ExecutionResultNormalizer
{
    return new ExecutionResultNormalizer(
        taxonomy: new FailureTaxonomy,
        classifier: new ProbeOutcomeClassifier(new FailureTaxonomy),
    );
}

function w1aHandler(
    ?ProbeTool $tool = null,
    ?ResourceGovernor $governor = null,
): WorkerExecutionPlaneHandler {
    $t = $tool ?? new FakeOkProbeTool;

    return new WorkerExecutionPlaneHandler(
        governor: $governor ?? w1aGovernor(),
        executor: w1aExecutor($t, $governor),
        normalizer: w1aNormalizer(),
        checkerNodeId: 'test-node',
    );
}

function w1aTaskPayload(array $probes = []): array
{
    $task = new AuditTaskV1(
        job: new JobRef(jobId: 'job-w1a', attemptId: 'att-w1a', taskId: 'task-w1a'),
        tenantId: 'tenant-1',
        accessRef: new AccessIdentity(
            endpoint: new EndpointIdentity(host: '198.51.100.1', port: 1080, protocol: ProxyProtocol::Socks5),
            credential: new CredentialFingerprint(str_repeat('a', 64)),
        ),
        sealedCredential: null,
        credentialReference: new CredentialReference(handle: 'handle-w1a'),
        probes: $probes !== [] ? $probes : [
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
        accessId: 'access-w1a',
    );

    return ['task' => $task->jsonSerialize()];
}

// --- Tests: backward-compatible scaffold behavior ---

it('create execution returns created status with id', function (): void {
    $handler = w1aHandler();
    $result = $handler->handle(ExecutionPlaneRoute::CreateExecution, []);

    expect($result['status'])->toBe('created')
        ->and($result['executionId'])->not->toBeEmpty();
});

it('create execution returns error when governor at capacity', function (): void {
    $governor = w1aGovernor(maxConcurrentProbes: 1);
    $governor->connectionOpened();

    $handler = w1aHandler(governor: $governor);
    $result = $handler->handle(ExecutionPlaneRoute::CreateExecution, []);

    expect($result['status'])->toBe('error')
        ->and($result['error'])->toBe('Resource governor at capacity');
});

it('get execution returns execution details for pending execution', function (): void {
    $handler = w1aHandler();
    $created = $handler->handle(ExecutionPlaneRoute::CreateExecution, []);
    $result = $handler->handle(ExecutionPlaneRoute::GetExecution, ['executionId' => $created['executionId']]);

    expect($result['status'])->toBe('ok')
        ->and($result['execution']['status'])->toBe('pending');
});

it('get execution returns error for unknown id', function (): void {
    $handler = w1aHandler();
    $result = $handler->handle(ExecutionPlaneRoute::GetExecution, ['executionId' => 'nonexistent']);

    expect($result['status'])->toBe('error')
        ->and($result['error'])->toBe('Execution not found');
});

it('cancel execution cancels an existing execution', function (): void {
    $handler = w1aHandler();
    $created = $handler->handle(ExecutionPlaneRoute::CreateExecution, []);
    $result = $handler->handle(ExecutionPlaneRoute::CancelExecution, ['executionId' => $created['executionId']]);

    expect($result['status'])->toBe('cancelled');
});

it('cancel execution returns error for unknown id', function (): void {
    $handler = w1aHandler();
    $result = $handler->handle(ExecutionPlaneRoute::CancelExecution, ['executionId' => 'nope']);

    expect($result['status'])->toBe('error')
        ->and($result['error'])->toBe('Execution not found');
});

it('executionCount tracks active executions', function (): void {
    $handler = w1aHandler();

    expect($handler->executionCount())->toBe(0);

    $handler->handle(ExecutionPlaneRoute::CreateExecution, []);
    expect($handler->executionCount())->toBe(1);
});

// --- Tests: W1a ProbeTool dispatch ---

it('dispatches probes and returns AuditResultV1 when task is provided', function (): void {
    $tool = new FakeOkProbeTool;
    $handler = w1aHandler(tool: $tool);

    $created = $handler->handle(ExecutionPlaneRoute::CreateExecution, w1aTaskPayload());

    expect($created['status'])->toBe('created')
        ->and($created['executionId'])->not->toBeEmpty();

    $get = $handler->handle(ExecutionPlaneRoute::GetExecution, ['executionId' => $created['executionId']]);

    expect($get['status'])->toBe('ok')
        ->and($get['execution']['status'])->toBe('completed')
        ->and($get['execution']['result'])->toBeArray()
        ->and($get['execution']['result']['taskId'])->toBe('task-w1a')
        ->and($get['execution']['result']['attemptId'])->toBe('att-w1a')
        ->and($get['execution']['result']['checkerNodeId'])->toBe('test-node')
        ->and($get['execution']['result']['accessId'])->toBe('access-w1a')
        ->and($get['execution']['result']['timings'])->toHaveKey('totalMs');
});

it('tool receives correct ProbeExecutionContext from dispatched task', function (): void {
    $tool = new FakeOkProbeTool;
    $handler = w1aHandler(tool: $tool);

    $handler->handle(ExecutionPlaneRoute::CreateExecution, w1aTaskPayload());

    expect($tool->receivedContexts)->toHaveCount(1)
        ->and($tool->receivedContexts[0]->host)->toBe('198.51.100.1')
        ->and($tool->receivedContexts[0]->port)->toBe(1080)
        ->and($tool->receivedContexts[0]->spec->probeType)->toBe(ProbeType::UdpAssociate)
        ->and($tool->receivedContexts[0]->spec->target)->toBe('8.8.8.8:53');
});

it('marks execution as failed when task execution throws', function (): void {
    $failingTool = new class implements ProbeTool
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
    };

    $handler = w1aHandler(tool: $failingTool);
    $created = $handler->handle(ExecutionPlaneRoute::CreateExecution, w1aTaskPayload());
    $get = $handler->handle(ExecutionPlaneRoute::GetExecution, ['executionId' => $created['executionId']]);

    expect($get['execution']['status'])->toBe('failed')
        ->and($get['execution']['result']['executionFailures'])->not->toBeEmpty();
});

it('releases governor slot after task execution completes', function (): void {
    $governor = w1aGovernor(maxConcurrentProbes: 2);
    $handler = w1aHandler(governor: $governor);

    $handler->handle(ExecutionPlaneRoute::CreateExecution, w1aTaskPayload());

    expect($governor->activeConnections())->toBe(0);
});

it('returns AuditResultV1 with execution failures when probe tool returns failure', function (): void {
    $failTool = new class implements ProbeTool
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
            return ProbeToolResult::failed(
                failure: new ExecutionFailure(
                    descriptor: (new FailureTaxonomy)->descriptor(FailureCode::ToolUnavailable),
                    context: ['reason' => 'test_failure'],
                ),
                timingsMs: [],
            );
        }
    };

    $handler = w1aHandler(tool: $failTool);
    $created = $handler->handle(ExecutionPlaneRoute::CreateExecution, w1aTaskPayload());
    $get = $handler->handle(ExecutionPlaneRoute::GetExecution, ['executionId' => $created['executionId']]);

    expect($get['execution']['result']['status'])->toBe('failed')
        ->and($get['execution']['result']['executionFailures'])->not->toBeEmpty();
});
