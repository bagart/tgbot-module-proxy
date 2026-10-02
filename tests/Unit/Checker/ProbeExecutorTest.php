<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\JudgeProvider;
use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Checker\ProbeSingleResult;
use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeDescriptor;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Tool\CredentialChannel;
use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
use BAGArt\ProxyOperations\Tool\ProbeTool;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Tool\ResourceGovernorSpec;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Tool\ToolCapabilities;
use BAGArt\ProxyOperations\Tool\ToolId;
use BAGArt\ProxyOperations\Tool\ToolLimits;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use BAGArt\ProxyOperations\Tool\ToolSecurity;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;
use BAGArt\ProxyOperations\Wire\SealedCredentialPayload;

/**
 * ProbeTool test double: returns the configured result or throws the
 * configured throwable; captures every received context.
 */
final class T20FakeProbeTool implements ProbeTool
{
    /** @var list<ProbeExecutionContext> */
    public array $receivedContexts = [];

    /** @var Closure(ProbeExecutionContext): ProbeToolResult */
    private Closure $behaviour;

    public function __construct(
        ?Closure $behaviour = null,
        private readonly ?ToolCapabilities $capabilityOverride = null,
    ) {
        $this->behaviour = $behaviour
            ?? static fn (ProbeExecutionContext $context): ProbeToolResult => ProbeToolResult::ok(
                observations: ['elapsed_ms' => 12.5],
                timingsMs: ['connect' => 12.5],
            );
    }

    public function capabilities(): ToolCapabilities
    {
        return $this->capabilityOverride ?? t20Capabilities();
    }

    public function execute(ProbeExecutionContext $context): ProbeToolResult
    {
        $this->receivedContexts[] = $context;

        return ($this->behaviour)($context);
    }
}

/**
 * JudgeProvider test double returning a fixed judge list.
 */
final class T20FakeJudgeProvider implements JudgeProvider
{
    /** @var list<array{0: JudgeSetSnapshot, 1: ProbeType, 2: int}> */
    public array $calls = [];

    public function __construct(
        private readonly array $judges = [],
    ) {
    }

    public function select(JudgeSetSnapshot $snapshot, ProbeType $probeType, int $count): array
    {
        $this->calls[] = [$snapshot, $probeType, $count];

        return array_slice($this->judges, 0, $count);
    }
}

function t20Capabilities(): ToolCapabilities
{
    return new ToolCapabilities(
        probeTypes: ProbeType::cases(),
        protocols: ['socks5'],
        inputSchemaVersion: 1,
        outputSchemaVersion: 1,
    );
}

function t20Registry(): ToolRegistry
{
    return new ToolRegistry([
        'socks-checker' => new ToolManifest(
            name: new ToolId('socks-checker'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: t20Capabilities(),
            limits: new ToolLimits(maxExecutionTimeSeconds: 60, maxOutputBytes: 1024 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        ),
    ]);
}

function t20Governor(int $maxConcurrentProbes = 4): ResourceGovernor
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

function t20Judge(): JudgeDescriptor
{
    return new JudgeDescriptor(
        id: 'j1',
        url: 'https://judge-j1.example.com/health',
        region: 'us-east',
        protocol: 'https',
        capabilities: [ProbeType::HttpLiveness->value, ProbeType::HeaderMarker->value],
        rateLimitPerMinute: 60,
        trustTier: BAGArt\ProxyOperations\Domain\Snapshot\JudgeTrustTier::SelfHosted,
    );
}

function t20JudgeSnapshot(array $judges): JudgeSetSnapshot
{
    return new JudgeSetSnapshot(
        setId: 'snap-1',
        version: 1,
        judges: $judges,
        frozenAt: '2026-08-27T00:00:00Z',
    );
}

function t20Task(array $probes, bool $sealed = true): AuditTaskV1
{
    return new AuditTaskV1(
        job: new JobRef(jobId: 'job-1', attemptId: 'att-1', taskId: 'task-1'),
        tenantId: 'tenant-1',
        accessRef: new AccessIdentity(
            endpoint: new EndpointIdentity(host: '203.0.113.10', port: 1080, protocol: ProxyProtocol::Socks5),
            credential: new CredentialFingerprint('fp:cafe'),
        ),
        sealedCredential: $sealed
            ? new SealedCredentialPayload(algId: 'a256gcm', ciphertext: 'ZmFrZQ==', nonce: 'bm9uY2U=')
            : null,
        credentialReference: $sealed ? null : new CredentialReference(handle: 'handle-1'),
        probes: $probes,
        policySnapshotVersion: 1,
        deadline: '2026-08-30T00:10:00Z',
        maxAttempts: 3,
    );
}

function t20Probe(
    ProbeType $type = ProbeType::UdpAssociate,
    ProbeProfile $profile = ProbeProfile::Standard,
    string $target = '8.8.8.8:53',
    int $timeoutMs = 5000,
): ProbeExecutionSpecV1 {
    return new ProbeExecutionSpecV1(
        probeType: $type,
        profile: $profile,
        target: $target,
        timeoutMs: $timeoutMs,
        maxOutputBytes: 65536,
    );
}

function t20Executor(
    ProbeTool $tool,
    ?JudgeProvider $judgeProvider = null,
    ?ResourceGovernor $governor = null,
    ?ToolRegistry $registry = null,
): ProbeExecutor {
    return new ProbeExecutor(
        tools: ['socks-checker' => $tool],
        judgeProvider: $judgeProvider ?? new T20FakeJudgeProvider(),
        toolRegistry: $registry ?? t20Registry(),
        governor: $governor ?? t20Governor(),
        timeoutFactory: new ToolTimeoutFactory(),
    );
}

it('executes two probes into two results on the happy path', function (): void {
    $tool = new T20FakeProbeTool();
    $executor = t20Executor($tool);

    $outcome = $executor->execute(t20Task([t20Probe(), t20Probe(ProbeType::DnsResolution)]));

    expect($outcome->taskId)->toBe('task-1')
        ->and($outcome->attemptId)->toBe('att-1')
        ->and($outcome->results)->toHaveCount(2)
        ->and($outcome->timingsMs)->toHaveCount(2)
        ->and($outcome->probeCount)->toBe(2)
        ->and($outcome->successCount)->toBe(2)
        ->and($outcome->failureCount)->toBe(0)
        ->and($outcome->executionFailureCount)->toBe(0)
        ->and($outcome->results[0])->toBeInstanceOf(ProbeSingleResult::class)
        ->and($outcome->results[0]->toolResult->ok)->toBeTrue();
});

it('selects a judge for judge-dependent probes and runs one execution per judge', function (): void {
    $judge = t20Judge();
    $provider = new T20FakeJudgeProvider([$judge]);
    $tool = new T20FakeProbeTool();
    $executor = t20Executor($tool, judgeProvider: $provider);

    $outcome = $executor->execute(
        t20Task([t20Probe(ProbeType::HttpLiveness, target: 'placeholder')]),
        t20JudgeSnapshot([$judge]),
    );

    expect($provider->calls)->toHaveCount(1)
        ->and($provider->calls[0][1])->toBe(ProbeType::HttpLiveness)
        ->and($outcome->results)->toHaveCount(1)
        ->and($outcome->results[0]->judgeId)->toBe('j1')
        // The judge URL replaces the spec target as the probe target.
        ->and($tool->receivedContexts[0]->spec->target)->toBe($judge->url)
        ->and($outcome->successCount)->toBe(1);
});

it('keeps the spec target and a null judgeId for non-judge probes', function (): void {
    $tool = new T20FakeProbeTool();
    $outcome = t20Executor($tool)->execute(t20Task([t20Probe(ProbeType::UdpAssociate)]));

    expect($outcome->results[0]->judgeId)->toBeNull()
        ->and($tool->receivedContexts[0]->spec->target)->toBe('8.8.8.8:53');
});

it('records a TOOL_UNAVAILABLE execution failure when the governor is at capacity', function (): void {
    $governor = t20Governor(maxConcurrentProbes: 1);
    $governor->connectionOpened(); // simulate a probe already in flight

    $tool = new T20FakeProbeTool();
    $outcome = t20Executor($tool, governor: $governor)->execute(t20Task([t20Probe()]));

    $failure = $outcome->results[0]->toolResult->failure;

    expect($outcome->executionFailureCount)->toBe(1)
        ->and($outcome->successCount)->toBe(0)
        ->and($failure)->toBeInstanceOf(ExecutionFailure::class)
        ->and($failure->descriptor->code)->toBe(FailureCode::ToolUnavailable)
        ->and($failure->context()['reason'])->toBe('governor_at_capacity')
        // The probe was skipped: the tool never ran.
        ->and($tool->receivedContexts)->toHaveCount(0)
        ->and($governor->activeConnections())->toBe(1);
});

it('records ExecutionFailure(ToolCrash) when the tool throws', function (): void {
    $tool = new T20FakeProbeTool(
        behaviour: static fn (): never => throw new RuntimeException('binary exploded'),
    );

    $outcome = t20Executor($tool)->execute(t20Task([t20Probe()]));

    $failure = $outcome->results[0]->toolResult->failure;

    expect($outcome->executionFailureCount)->toBe(1)
        ->and($failure)->toBeInstanceOf(ExecutionFailure::class)
        ->and($failure->descriptor->code)->toBe(FailureCode::ToolCrash)
        ->and($failure->context()['exception_class'])->toBe(RuntimeException::class)
        ->and($failure->context()['message'])->toBe('binary exploded');
});

it('records ExecutionFailure(ToolTimeout) when the wall-clock limit is exceeded', function (): void {
    // Spec budget 50ms; the tool sleeps 200ms.
    $tool = new T20FakeProbeTool(
        behaviour: static function (): ProbeToolResult {
            usleep(200_000);

            return ProbeToolResult::ok(observations: [], timingsMs: []);
        },
    );

    $outcome = t20Executor($tool)->execute(t20Task([t20Probe(timeoutMs: 50)]));

    $failure = $outcome->results[0]->toolResult->failure;

    expect($outcome->executionFailureCount)->toBe(1)
        ->and($failure)->toBeInstanceOf(ExecutionFailure::class)
        ->and($failure->descriptor->code)->toBe(FailureCode::ToolTimeout)
        ->and($failure->context()['limitMs'])->toBe(50)
        ->and($failure->context()['elapsedMs'])->toBeGreaterThan(50.0);
});

it('counts mixed success, proxy failure and execution failure correctly', function (): void {
    $okTool = new T20FakeProbeTool(); // probe 1: success

    $crashTool = new T20FakeProbeTool(
        behaviour: static fn (): never => throw new LogicException('crash'),
    );

    // Two dispatches through different tools: build two executors manually.
    $task = t20Task([t20Probe(ProbeType::UdpAssociate), t20Probe(ProbeType::DnsResolution)]);

    $first = t20Executor($okTool)->execute(t20Task([$task->probes[0]]));
    $second = t20Executor($crashTool)->execute(t20Task([$task->probes[1]]));

    $results = [...$first->results, ...$second->results];
    $timings = [...$first->timingsMs, ...$second->timingsMs];

    $outcome = new BAGArt\ProxyOperations\Checker\ProbeExecutionOutcome(
        taskId: $first->taskId,
        attemptId: $first->attemptId,
        results: $results,
        timingsMs: $timings,
        probeCount: count($results),
        successCount: count(array_filter($results, static fn (ProbeSingleResult $r): bool => $r->toolResult->ok)),
        failureCount: count(array_filter(
            $results,
            static fn (ProbeSingleResult $r): bool => ! $r->toolResult->ok && $r->toolResult->failure === null,
        )),
        executionFailureCount: count(array_filter(
            $results,
            static fn (ProbeSingleResult $r): bool => $r->toolResult->failure instanceof ExecutionFailure,
        )),
    );

    expect($outcome->probeCount)->toBe(2)
        ->and($outcome->successCount)->toBe(1)
        ->and($outcome->failureCount)->toBe(0)
        ->and($outcome->executionFailureCount)->toBe(1);
});

it('returns an empty outcome with zero counts for an empty probe list', function (): void {
    $outcome = t20Executor(new T20FakeProbeTool())->execute(t20Task([]));

    expect($outcome->results)->toBe([])
        ->and($outcome->timingsMs)->toBe([])
        ->and($outcome->probeCount)->toBe(0)
        ->and($outcome->successCount)->toBe(0)
        ->and($outcome->failureCount)->toBe(0)
        ->and($outcome->executionFailureCount)->toBe(0);
});

it('records ExecutionFailure(ToolUnavailable) when no registry tool covers the probe type', function (): void {
    $registry = new ToolRegistry([
        'dns-only' => new ToolManifest(
            name: new ToolId('dns-only'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: [ProbeType::DnsResolution],
                protocols: ['socks5'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 60, maxOutputBytes: 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        ),
    ]);

    $tool = new T20FakeProbeTool(); // supports everything, but is not bound under 'dns-only' either

    $outcome = t20Executor($tool, registry: $registry)->execute(t20Task([t20Probe(ProbeType::UdpAssociate)]));

    $failure = $outcome->results[0]->toolResult->failure;

    expect($outcome->executionFailureCount)->toBe(1)
        ->and($failure)->toBeInstanceOf(ExecutionFailure::class)
        ->and($failure->descriptor->code)->toBe(FailureCode::ToolUnavailable)
        ->and($failure->context()['reason'])->toBe('tool_not_found')
        ->and($tool->receivedContexts)->toHaveCount(0);
});

it('delivers the sealed credential as a channel, never the payload itself', function (): void {
    $tool = new T20FakeProbeTool();
    t20Executor($tool)->execute(t20Task([t20Probe()], sealed: true));

    $credentials = $tool->receivedContexts[0]->credentials;

    expect($credentials)->toBeInstanceOf(CredentialChannel::class)
        ->and($credentials)->toBeInstanceOf(StdinChannel::class);
});

it('builds the context from the endpoint snapshot with governor limits', function (): void {
    $tool = new T20FakeProbeTool();
    t20Executor($tool)->execute(t20Task([t20Probe(timeoutMs: 4321)]));

    $context = $tool->receivedContexts[0];

    expect($context->host)->toBe('203.0.113.10')
        ->and($context->port)->toBe(1080)
        ->and($context->timeoutMs)->toBe(4321)
        ->and($context->maxOutputBytes)->toBe(65536)
        ->and($context->spec->probeType)->toBe(ProbeType::UdpAssociate);
});

it('releases the governor slot after each probe execution', function (): void {
    $governor = t20Governor(maxConcurrentProbes: 1);
    $tool = new T20FakeProbeTool();

    $outcome = t20Executor($tool, governor: $governor)
        ->execute(t20Task([t20Probe(), t20Probe(ProbeType::DnsResolution)]));

    expect($outcome->successCount)->toBe(2)
        ->and($governor->activeConnections())->toBe(0);
});
