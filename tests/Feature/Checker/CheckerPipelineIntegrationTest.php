<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\ExecutionResultNormalizer;
use BAGArt\ProxyOperations\Checker\JudgeBudgetTracker;
use BAGArt\ProxyOperations\Checker\JudgeProvider;
use BAGArt\ProxyOperations\Checker\JudgeSetProvider;
use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Checker\ProbeOutcomeClassifier;
use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeDescriptor;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeTrustTier;
use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
use BAGArt\ProxyOperations\Tool\ProbeTool;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Tool\ToolCapabilities;
use BAGArt\ProxyOperations\Tool\ToolId;
use BAGArt\ProxyOperations\Tool\ToolLimits;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use BAGArt\ProxyOperations\Tool\ToolSecurity;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

/**
 * Deterministic ProbeTool double for the T22 wiring integration tests.
 */
final class T22FakeProbeTool implements ProbeTool
{
    /** @var list<ProbeExecutionContext> */
    public array $receivedContexts = [];

    public function __construct(
        private readonly ?Closure $behaviour = null,
    ) {}

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

        return ($this->behaviour ?? static fn (): ProbeToolResult => ProbeToolResult::ok(
            observations: ['elapsed_ms' => 12.5],
            timingsMs: ['connect' => 12.5],
        ))($context);
    }
}

function t22Registry(): ToolRegistry
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

function t22Task(array $probes): AuditTaskV1
{
    return new AuditTaskV1(
        job: new JobRef(jobId: 'job-1', attemptId: 'att-1', taskId: 'task-1'),
        tenantId: 'tenant-1',
        accessRef: new AccessIdentity(
            endpoint: new EndpointIdentity(host: '203.0.113.10', port: 1080, protocol: ProxyProtocol::Socks5),
            credential: new CredentialFingerprint('fp:cafe'),
        ),
        sealedCredential: null,
        credentialReference: new CredentialReference(handle: 'handle-1'),
        probes: $probes,
        policySnapshotVersion: 1,
        deadline: '2026-08-30T00:10:00Z',
        maxAttempts: 3,
    );
}

function t22Probe(ProbeType $type, string $target = '8.8.8.8:53'): ProbeExecutionSpecV1
{
    return new ProbeExecutionSpecV1(
        probeType: $type,
        profile: ProbeProfile::Standard,
        target: $target,
        timeoutMs: 15000,
        maxOutputBytes: 65536,
    );
}

function t22Judge(string $id): JudgeDescriptor
{
    return new JudgeDescriptor(
        id: $id,
        url: 'https://'.$id.'.example.com/health',
        region: 'us-east',
        protocol: 'https',
        capabilities: [ProbeType::HttpLiveness->value],
        rateLimitPerMinute: 60,
        trustTier: JudgeTrustTier::SelfHosted,
    );
}

function t22JudgeSnapshot(array $judges): JudgeSetSnapshot
{
    return new JudgeSetSnapshot(
        setId: 'snap-1',
        version: 1,
        judges: $judges,
        frozenAt: '2026-08-30T00:00:00Z',
    );
}

/**
 * Rebind the module's ProbeExecutor singleton so it carries the given tool
 * map while keeping the container-resolved infrastructure (judge provider,
 * governor, timeout factory).
 */
function t22BindExecutor(ToolRegistry $registry, array $tools): void
{
    app()->singleton(ProbeExecutor::class, static function ($app) use ($registry, $tools) {
        return new ProbeExecutor(
            tools: $tools,
            judgeProvider: $app->make(JudgeProvider::class),
            toolRegistry: $registry,
            governor: $app->make(ResourceGovernor::class),
            timeoutFactory: $app->make(ToolTimeoutFactory::class),
        );
    });
}

it('resolves all checker singletons from the container', function (): void {
    expect(app(ToolTimeoutFactory::class))->toBeInstanceOf(ToolTimeoutFactory::class)
        ->and(app(ProbeOutcomeClassifier::class))->toBeInstanceOf(ProbeOutcomeClassifier::class)
        ->and(app(ExecutionResultNormalizer::class))->toBeInstanceOf(ExecutionResultNormalizer::class)
        ->and(app(JudgeProvider::class))->toBeInstanceOf(JudgeSetProvider::class)
        ->and(app(ProbeExecutor::class))->toBeInstanceOf(ProbeExecutor::class)
        // Singletons: same instance on re-resolution.
        ->and(app(JudgeProvider::class))->toBe(app(JudgeProvider::class))
        ->and(app(ProbeExecutor::class))->toBe(app(ProbeExecutor::class))
        // JudgeBudgetTracker is intentionally transient (T22).
        ->and(app(JudgeBudgetTracker::class))->not->toBe(app(JudgeBudgetTracker::class));
});

it('exposes the checker config section', function (): void {
    expect(config('proxy-operations.checker.timeouts'))->toBe([
        'aggressive' => 5000,
        'standard' => 15000,
        'generous' => 30000,
    ])
        ->and(config('proxy-operations.checker.judge_selection'))->toBe('round_robin')
        ->and(config('proxy-operations.checker.max_probes_per_task'))->toBe(50)
        ->and(config('proxy-operations.checker.judge_budget'))->toBe([
            'rate_limit_per_minute' => 60,
            'window_seconds' => 60,
        ]);
});

it('executes the full AuditTaskV1 → ProbeExecutor → normalizer → AuditResultV1 pipeline', function (): void {
    $tool = new T22FakeProbeTool;
    t22BindExecutor(t22Registry(), ['socks-checker' => $tool]);

    $task = t22Task([
        t22Probe(ProbeType::HttpLiveness, target: 'placeholder'),
        t22Probe(ProbeType::LatencySeries),
    ]);
    $judgeSet = t22JudgeSnapshot([t22Judge('j1'), t22Judge('j2')]);

    $outcome = app(ProbeExecutor::class)->execute($task, $judgeSet);
    $result = app(ExecutionResultNormalizer::class)->normalize($outcome, 'checker-1');

    expect($result)->toBeInstanceOf(AuditResultV1::class)
        ->and(AuditResultV1::SCHEMA_VERSION)->toBe(1)
        ->and($result->status)->toBe(AuditResultStatus::Completed)
        ->and($result->observations)->toBe([])
        ->and($result->executionFailures)->toBe([])
        ->and($result->checkerNodeId)->toBe('checker-1')
        ->and($result->taskId)->toBe('task-1')
        ->and($result->attemptId)->toBe('att-1')
        // Judge fan-out: one execution for http_liveness + one for latency_series.
        ->and($outcome->results)->toHaveCount(2)
        ->and($outcome->results[0]->judgeId)->toBe('j1')
        ->and($outcome->results[1]->judgeId)->toBeNull()
        ->and($outcome->successCount)->toBe(2)
        ->and($outcome->failureCount)->toBe(0)
        ->and($outcome->executionFailureCount)->toBe(0)
        ->and(array_keys($result->timings))->toContain('totalMs')
        ->and(array_keys($result->timings))->toContain('connect')
        // The tool received the judge URL as the http_liveness target.
        ->and($tool->receivedContexts[0]->spec->target)->toBe('https://j1.example.com/health');
});
