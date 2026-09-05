<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Audit\CachePolicy;
use BAGArt\ProxyOperations\Audit\LaravelCacheProbeCache;
use BAGArt\ProxyOperations\Audit\ProbeCache;
use BAGArt\ProxyOperations\Audit\ProbeCacheKeyFactory;
use BAGArt\ProxyOperations\Audit\ProbeCacheMetrics;
use BAGArt\ProxyOperations\Checker\CacheAwareProbePlanner;
use BAGArt\ProxyOperations\Checker\JudgeProvider;
use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Checker\ProbeSingleResult;
use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValueKind;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Identity\AccessIdentity;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
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
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\CredentialReference;
use BAGArt\ProxyOperations\Wire\JobRef;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

/**
 * Deterministic ProbeTool double for the T30 planner tests: records every
 * dispatch and delegates the outcome to a behaviour closure.
 */
final class T30FakeProbeTool implements ProbeTool
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
            observations: ['elapsed_ms' => 12.5, 'exit_ip' => '203.0.113.99'],
            timingsMs: ['connect' => 12.5],
        ))($context);
    }
}

/**
 * In-memory ProbeCacheMetrics recorder for assertions.
 */
final class T30RecordingMetrics implements ProbeCacheMetrics
{
    /** @var array<string, int> */
    public array $counts = [];

    public function hit(string $kind): void
    {
        $this->counts['hit:'.$kind] = ($this->counts['hit:'.$kind] ?? 0) + 1;
    }

    public function miss(string $kind): void
    {
        $this->counts['miss:'.$kind] = ($this->counts['miss:'.$kind] ?? 0) + 1;
    }

    public function negativeHit(string $kind): void
    {
        $this->counts['negative_hit:'.$kind] = ($this->counts['negative_hit:'.$kind] ?? 0) + 1;
    }

    public function stored(string $kind): void
    {
        $this->counts['stored:'.$kind] = ($this->counts['stored:'.$kind] ?? 0) + 1;
    }
}

function t30Task(?ProbeExecutionSpecV1 $withProbe = null): AuditTaskV1
{
    return new AuditTaskV1(
        job: new JobRef(jobId: 'job-1', attemptId: 'att-1', taskId: 'task-1'),
        tenantId: 'tenant-1',
        accessRef: new AccessIdentity(
            endpoint: new EndpointIdentity(host: '203.0.113.10', port: 1080, protocol: ProxyProtocol::Socks5),
            credential: new CredentialFingerprint(str_repeat('ab', 32)),
        ),
        sealedCredential: null,
        credentialReference: new CredentialReference(handle: 'handle-1'),
        probes: $withProbe === null ? [] : [$withProbe],
        policySnapshotVersion: 7,
        deadline: '2026-08-30T00:10:00Z',
        maxAttempts: 3,
    );
}

function t30Probe(): ProbeExecutionSpecV1
{
    return new ProbeExecutionSpecV1(
        probeType: ProbeType::LatencySeries,
        profile: ProbeProfile::Standard,
        target: '8.8.8.8:53',
        timeoutMs: 15000,
        maxOutputBytes: 65536,
    );
}

function t30Executor(ProbeTool $tool): ProbeExecutor
{
    return new ProbeExecutor(
        tools: ['socks-checker' => $tool],
        judgeProvider: app(JudgeProvider::class),
        toolRegistry: new ToolRegistry([
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
        ]),
        governor: app(ResourceGovernor::class),
        timeoutFactory: app(ToolTimeoutFactory::class),
    );
}

function t30Policy(bool $enabled = true): CachePolicy
{
    $policy = CachePolicy::fromConfig(config('proxy-operations.audit.cache'));

    return new CachePolicy(
        enabled: $enabled,
        keyPrefix: $policy->keyPrefix,
        negativeTtlSeconds: $policy->negativeTtlSeconds,
        ttlByKind: $policy->ttlByKind,
        defaultTtlSeconds: $policy->defaultTtlSeconds,
    );
}

function t30Planner(ProbeExecutor $executor, CachePolicy $policy, ProbeCacheMetrics $metrics): CacheAwareProbePlanner
{
    return new CacheAwareProbePlanner(
        executor: $executor,
        cache: new LaravelCacheProbeCache($policy),
        policy: $policy,
        keyFactory: new ProbeCacheKeyFactory([
            'checker_node_id' => 'node-1',
            'egress_identity' => 'local',
            'judge_set_version' => 1,
            'tg_dc_set_version' => 1,
            'tool_semantics_version' => 'builtin-v1',
        ]),
        metrics: $metrics,
    );
}

function t30Counters(array $results): array
{
    return [
        'probeCount' => count($results),
        'successCount' => count(array_filter($results, static fn (ProbeSingleResult $r): bool => $r->toolResult->ok)),
        'failureCount' => count(array_filter(
            $results,
            static fn (ProbeSingleResult $r): bool => ! $r->toolResult->ok && $r->toolResult->failure === null,
        )),
        'executionFailureCount' => count(array_filter(
            $results,
            static fn (ProbeSingleResult $r): bool => $r->toolResult->failure instanceof ExecutionFailure,
        )),
    ];
}

it('stores on miss and serves an identical result from cache on the second run without dispatching', function (): void {
    $tool = new T30FakeProbeTool;
    $probe = t30Probe();
    $task = t30Task();
    $metrics = new T30RecordingMetrics;

    $planner = t30Planner(t30Executor($tool), t30Policy(), $metrics);
    $first = $planner->run($task, $probe, null);

    expect($first)->toHaveCount(1)
        ->and($first[0]->toolResult->ok)->toBeTrue()
        ->and($tool->receivedContexts)->toHaveCount(1)
        ->and($metrics->counts)->toBe(['miss:timing_measurement' => 1, 'stored:timing_measurement' => 1]);

    // Cold executor: any dispatch would produce a crashed result instead of
    // the cached success.
    $coldTool = new T30FakeProbeTool(behaviour: static fn (): ProbeToolResult => throw new RuntimeException('must not dispatch'));
    $coldPlanner = t30Planner(t30Executor($coldTool), t30Policy(), new T30RecordingMetrics);
    $second = $coldPlanner->run($task, $probe, null);

    expect($second)->toHaveCount(1)
        ->and($second[0])->toEqual($first[0])
        ->and($coldTool->receivedContexts)->toHaveCount(0)
        ->and($metrics->counts['hit:timing_measurement'] ?? 0)->toBe(0);

    $hitMetrics = new T30RecordingMetrics;
    t30Planner(t30Executor($coldTool), t30Policy(), $hitMetrics)->run($task, $probe, null);

    expect($hitMetrics->counts)->toBe(['hit:timing_measurement' => 1]);
});

it('short-circuits a classifyable TOOL_* failure from the negative cache without dispatching', function (): void {
    $taxonomy = new FailureTaxonomy;
    $failing = new T30FakeProbeTool(behaviour: static fn (): ProbeToolResult => ProbeToolResult::failed(
        new ExecutionFailure($taxonomy->descriptor(FailureCode::ToolTimeout), ['elapsedMs' => 15001.0]),
        [],
    ));
    $probe = t30Probe();
    $task = t30Task();
    $metrics = new T30RecordingMetrics;

    $first = t30Planner(t30Executor($failing), t30Policy(), $metrics)->run($task, $probe, null);

    expect($first[0]->toolResult->ok)->toBeFalse()
        ->and($first[0]->toolResult->failure?->descriptor()->code)->toBe(FailureCode::ToolTimeout)
        ->and($failing->receivedContexts)->toHaveCount(1)
        ->and($metrics->counts)->toBe(['miss:timing_measurement' => 1, 'stored:timing_measurement' => 1]);

    $coldTool = new T30FakeProbeTool(behaviour: static fn (): ProbeToolResult => throw new RuntimeException('must not dispatch'));
    $secondMetrics = new T30RecordingMetrics;
    $second = t30Planner(t30Executor($coldTool), t30Policy(), $secondMetrics)->run($task, $probe, null);

    expect($second[0]->toolResult->ok)->toBeFalse()
        ->and($second[0]->toolResult->failure?->descriptor()->code)->toBe(FailureCode::ToolTimeout)
        ->and($coldTool->receivedContexts)->toHaveCount(0)
        ->and($secondMetrics->counts)->toBe(['negative_hit:timing_measurement' => 1]);
});

it('does not cache non-classifiable execution failures and dispatches again', function (): void {
    $taxonomy = new FailureTaxonomy;
    $tool = new T30FakeProbeTool(behaviour: static fn (): ProbeToolResult => ProbeToolResult::failed(
        new ExecutionFailure($taxonomy->descriptor(FailureCode::ToolUnavailable), ['reason' => 'governor_at_capacity']),
        [],
    ));
    $probe = t30Probe();
    $task = t30Task();
    $metrics = new T30RecordingMetrics;

    $planner = t30Planner(t30Executor($tool), t30Policy(), $metrics);
    $planner->run($task, $probe, null);
    $planner->run($task, $probe, null);

    expect($tool->receivedContexts)->toHaveCount(2)
        ->and($metrics->counts)->toBe(['miss:timing_measurement' => 2]);
});

it('never touches the cache when the policy is disabled', function (): void {
    $cache = Mockery::mock(ProbeCache::class);
    $cache->shouldNotReceive('get');
    $cache->shouldNotReceive('put');
    $cache->shouldNotReceive('putNegative');
    $cache->shouldNotReceive('getNegative');

    $tool = new T30FakeProbeTool;
    $probe = t30Probe();
    $task = t30Task();
    $metrics = new T30RecordingMetrics;

    $planner = new CacheAwareProbePlanner(
        executor: t30Executor($tool),
        cache: $cache,
        policy: t30Policy(enabled: false),
        keyFactory: new ProbeCacheKeyFactory([]),
        metrics: $metrics,
    );

    $first = $planner->run($task, $probe, null);
    $second = $planner->run($task, $probe, null);

    expect($first)->toHaveCount(1)
        ->and($second)->toHaveCount(1)
        ->and($tool->receivedContexts)->toHaveCount(2)
        ->and($metrics->counts)->toBe([]);
});

it('produces downstream-identical ProbeExecutionOutcome counters from cache', function (): void {
    $probe = t30Probe();
    $task = t30Task();

    // Fresh execution baseline through the bare executor.
    $freshTool = new T30FakeProbeTool;
    $fresh = t30Executor($freshTool)->execute(t30Task($probe), null);
    $freshCounters = [
        'probeCount' => $fresh->probeCount,
        'successCount' => $fresh->successCount,
        'failureCount' => $fresh->failureCount,
        'executionFailureCount' => $fresh->executionFailureCount,
    ];

    $tool = new T30FakeProbeTool;
    $planner = t30Planner(t30Executor($tool), t30Policy(), new T30RecordingMetrics);

    $missRun = t30Counters($planner->run($task, $probe, null));

    $coldTool = new T30FakeProbeTool(behaviour: static fn (): ProbeToolResult => throw new RuntimeException('must not dispatch'));
    $coldPlanner = t30Planner(t30Executor($coldTool), t30Policy(), new T30RecordingMetrics);
    $hitRun = t30Counters($coldPlanner->run($task, $probe, null));

    expect($missRun)->toBe($freshCounters)
        ->and($hitRun)->toBe($freshCounters)
        // Cached evidence keeps raw observation and timing values verbatim.
        ->and($hitRun['successCount'])->toBe(1);
});

it('drops non-scalar observation values before storing instead of throwing', function (): void {
    $tool = new T30FakeProbeTool(behaviour: static fn (): ProbeToolResult => ProbeToolResult::ok(
        observations: ['elapsed_ms' => 5.0, 'headers' => ['x-a' => 'b'], 'status' => 200],
        timingsMs: ['connect' => 5.0],
    ));
    $probe = t30Probe();
    $task = t30Task();

    $planner = t30Planner(t30Executor($tool), t30Policy(), new T30RecordingMetrics);
    $planner->run($task, $probe, null);

    $coldTool = new T30FakeProbeTool(behaviour: static fn (): ProbeToolResult => throw new RuntimeException('must not dispatch'));
    $second = t30Planner(t30Executor($coldTool), t30Policy(), new T30RecordingMetrics)->run($task, $probe, null);

    expect($second[0]->toolResult->ok)->toBeTrue()
        // Integer-valued floats come back as ints after the JSON-backed
        // store round-trip (store serialization artifact); value equality is
        // what downstream consumes, timings are re-cast to float by the planner.
        ->and($second[0]->toolResult->observations)->toEqual(['elapsed_ms' => 5.0, 'status' => 200])
        ->and($second[0]->toolResult->timingsMs)->toEqual(['connect' => 5.0]);
});
