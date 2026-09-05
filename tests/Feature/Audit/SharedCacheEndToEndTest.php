<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\AuditDeliveryQueue;
use BAGArt\ProxyOperations\Audit\AuditRequest;
use BAGArt\ProxyOperations\Audit\DbAuditEventRecorder;
use BAGArt\ProxyOperations\Audit\DeliveryDispatcher;
use BAGArt\ProxyOperations\Audit\EventTypeRegistry;
use BAGArt\ProxyOperations\Audit\HealthEvaluator;
use BAGArt\ProxyOperations\Audit\JobStarter;
use BAGArt\ProxyOperations\Audit\LaravelCacheProbeCacheMetrics;
use BAGArt\ProxyOperations\Audit\ObservationWriter;
use BAGArt\ProxyOperations\Audit\ProbeCacheMetrics;
use BAGArt\ProxyOperations\Audit\ProbeDataEvidenceExtractor;
use BAGArt\ProxyOperations\Audit\ResultIngestionService;
use BAGArt\ProxyOperations\Checker\CacheAwareProbePlanner;
use BAGArt\ProxyOperations\Checker\JudgeProvider;
use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Checker\ProbeSingleResult;
use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeDescriptor;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeTrustTier;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Models\ProxyObservation;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tests\Fixtures\InMemoryAuditDeliveryQueue;
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
use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\AuditTrigger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * Stage 6 exit (T31): the shared raw-probe cache ON across the checker
 * pipeline (§11.14). Two tenants import the identical endpoint+credential
 * pair; the delivery→worker(planner)→ingestion path is exercised with the
 * container wiring from ProxyOperationsServiceProvider, a fake queue and a
 * deterministic tool — including negative caching, store-outage failure
 * injection and the Laravel-cache-backed metrics counters.
 */
beforeEach(function (): void {
    config()->set([
        'cache.default' => 'array',
        'proxy-operations.encryption.kek' => 'proxy-enc-test-kek-v1',
        'proxy-operations.encryption.key_version' => 'k1',
        'proxy-operations.encryption.historical_keks' => [],
        'proxy-operations.audit.delivery.seal_key' => 'proxy-audit-seal-test-key',
    ]);

    Cache::flush();

    $this->tenantA = User::factory()->create()->id;
    $this->tenantB = User::factory()->create()->id;

    // One identical endpoint+credential pair per tenant — the fingerprints
    // must match (R6.2: tenant_id is not in the fingerprint).
    $this->accessA = t31Access($this->tenantA);
    $this->accessB = t31Access($this->tenantB);

    // The credential relation is tenant-scoped (INV-006): resolve each
    // fingerprint under its own tenant context.
    $context = app(TenantContext::class);
    $context->set($this->tenantA);
    $fingerprintA = $this->accessA->credentialFingerprint();
    $context->set($this->tenantB);
    $fingerprintB = $this->accessB->credentialFingerprint();

    expect($fingerprintA)->toBe($fingerprintB);

    // Fake queue in place of the production Redis Streams binding.
    $this->queue = new InMemoryAuditDeliveryQueue;
    app()->instance(AuditDeliveryQueue::class, $this->queue);

    // Worker posture: fake tool behind the container executor, planner
    // resolved through the provider wiring, recording metrics.
    $this->tool = new T31FakeProbeTool;
    $this->metrics = new T31RecordingMetrics;
    t31BindWorker($this->tool, $this->metrics);

    // Real evaluator + real recorder inside the T26 ingestion unit of work.
    $this->ingestion = new ResultIngestionService(
        evidenceExtractor: new ProbeDataEvidenceExtractor,
        healthEvaluator: app(HealthEvaluator::class),
        eventRecorder: new DbAuditEventRecorder(app(EventTypeRegistry::class)),
        observationWriter: new ObservationWriter,
    );
    app()->instance(ResultIngestionService::class, $this->ingestion);
});

/**
 * Scenarios 1–3 + 6 in one run: tenant A primes the cache (MISS → dispatch →
 * store), tenant B is served entirely from the shared cache (HIT → ZERO
 * re-dispatch) yet receives its own observation row and its own per-tenant
 * health interpretation from the same cached raw evidence (INV-005); metrics
 * counters move accordingly.
 */
it('serves tenant B from tenant A cached probe evidence with zero re-dispatch and per-tenant interpretation', function (): void {
    $taskA = t31Deliver($this->tenantA, $this->accessA);
    $resultsA = t31RunWorker($taskA);

    expect($resultsA)->toHaveCount(3)
        ->and($this->tool->receivedContexts)->toHaveCount(3)
        ->and($this->metrics->counts)->toBe([
            'miss:http_measurement' => 1,
            'stored:http_measurement' => 1,
            'miss:marker_result' => 1,
            'stored:marker_result' => 1,
            'miss:anonymity_header_flags' => 1,
            'stored:anonymity_header_flags' => 1,
        ]);

    $outcomeA = t31Ingest($this->tenantA, t31Result($this->accessA, $taskA, $resultsA));

    expect($outcomeA->duplicate)->toBeFalse()
        ->and(ProxyAuditAttempt::query()->findOrFail($taskA->job->attemptId)->status)->toBe(AuditAttemptStatus::Completed)
        ->and(ProxyAuditJob::query()->findOrFail($taskA->job->jobId)->status)->toBe(AuditJobStatus::Completed)
        ->and(ProxyObservation::query()->where('access_id', $this->accessA->id)->count())->toBe(1)
        ->and(ProxyHealth::query()->where('access_id', $this->accessA->id)->count())->toBe(1);

    // Tenant B, identical endpoint+credential: cache HIT for all three probe
    // kinds — the executor fake records ZERO additional dispatches.
    $taskB = t31Deliver($this->tenantB, $this->accessB);
    $resultsB = t31RunWorker($taskB);

    expect($resultsB)->toHaveCount(3)
        ->and($this->tool->receivedContexts)->toHaveCount(3)
        ->and($this->metrics->counts)->toBe([
            'miss:http_measurement' => 1,
            'stored:http_measurement' => 1,
            'miss:marker_result' => 1,
            'stored:marker_result' => 1,
            'miss:anonymity_header_flags' => 1,
            'stored:anonymity_header_flags' => 1,
            'hit:http_measurement' => 1,
            'hit:marker_result' => 1,
            'hit:anonymity_header_flags' => 1,
        ])
        // The cached raw evidence is exactly what tenant A's dispatch produced.
        ->and($resultsB)->toEqual($resultsA);

    $outcomeB = t31Ingest($this->tenantB, t31Result($this->accessB, $taskB, $resultsB));

    expect($outcomeB->duplicate)->toBeFalse()
        // Per-tenant observation and interpretation from the shared evidence
        // (§11.14: cache shares raw observations, never interpretations).
        ->and(ProxyObservation::query()->where('access_id', $this->accessB->id)->count())->toBe(1)
        // The observations table holds one row per tenant (tenant-scoped
        // queries hide the other tenant's row, INV-006).
        ->and(ProxyObservation::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(ProxyHealth::query()->where('access_id', $this->accessB->id)->count())->toBe(1)
        // The health interpretation ran per tenant: both accesses were
        // checked and evaluated independently from the same cached evidence.
        ->and($this->accessB->refresh()->last_checked_at)->not->toBeNull()
        ->and(ProxyHealth::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and($this->accessA->refresh()->last_checked_at)->not->toBeNull();
});

/**
 * Scenario 4: negative caching — a classifyable TOOL_* failure is remembered
 * for the shared key, short-circuiting both tenants without dispatch; after
 * the negative TTL passes, the endpoint is probed again.
 */
it('short-circuits both tenants through the negative cache until the negative TTL passes', function (): void {
    $taxonomy = new FailureTaxonomy;
    $this->tool->behaviour = static fn (): ProbeToolResult => ProbeToolResult::failed(
        new ExecutionFailure($taxonomy->descriptor(FailureCode::ToolTimeout), ['elapsedMs' => 15001.0]),
        [],
    );

    $taskA = t31Deliver($this->tenantA, $this->accessA);
    $resultsA = t31RunWorker($taskA);

    expect($resultsA)->toHaveCount(3)
        ->and($resultsA[0]->toolResult->failure?->descriptor()->code)->toBe(FailureCode::ToolTimeout)
        ->and($this->tool->receivedContexts)->toHaveCount(3)
        // Negative markers are stored under each probe kind's key.
        ->and($this->metrics->counts)->toBe([
            'miss:http_measurement' => 1,
            'stored:http_measurement' => 1,
            'miss:marker_result' => 1,
            'stored:marker_result' => 1,
            'miss:anonymity_header_flags' => 1,
            'stored:anonymity_header_flags' => 1,
        ]);

    $outcomeA = t31Ingest($this->tenantA, t31Result($this->accessA, $taskA, $resultsA));

    expect($outcomeA->observationId)->toBeNull()
        ->and(ProxyObservation::query()->withoutGlobalScopes()->count())->toBe(0);

    // Tenant B: short-circuit from the negative cache — zero dispatches.
    $taskB = t31Deliver($this->tenantB, $this->accessB);
    $resultsB = t31RunWorker($taskB);

    expect($resultsB)->toHaveCount(3)
        ->and($resultsB[0]->toolResult->failure?->descriptor()->code)->toBe(FailureCode::ToolTimeout)
        ->and($this->tool->receivedContexts)->toHaveCount(3)
        ->and($this->metrics->counts['negative_hit:http_measurement'] ?? 0)->toBe(1)
        ->and($this->metrics->counts['negative_hit:marker_result'] ?? 0)->toBe(1)
        ->and($this->metrics->counts['negative_hit:anonymity_header_flags'] ?? 0)->toBe(1);

    // Time travel past the negative TTL (120s): the endpoint is probed again.
    $afterTtl = Date::now()->addSeconds(121);
    Date::setTestNow($afterTtl);

    try {
        $dispatched = count($this->tool->receivedContexts);
        t31RunWorker(t31Deliver($this->tenantA, $this->accessA));

        expect(count($this->tool->receivedContexts))->toBe($dispatched + 3)
            ->and($this->metrics->counts['miss:http_measurement'] ?? 0)->toBe(2);
    } finally {
        Date::setTestNow();
    }
});

/**
 * Scenario 5: failure injection — a cache store throwing mid-run must never
 * break the pipeline: the result comes from a fresh dispatch and the audit
 * completes normally.
 */
it('completes the audit from a fresh dispatch when the cache store throws mid-run', function (): void {
    // Deliver first: the placement dedup needs a healthy store. Then take the
    // store down for the worker run itself.
    $taskA = t31Deliver($this->tenantA, $this->accessA);
    $taskB = t31Deliver($this->tenantB, $this->accessB);

    Cache::shouldReceive('get')->andReturn(null);
    Cache::shouldReceive('put')->andThrow(new RuntimeException('store outage'));
    Cache::shouldReceive('increment')->andThrow(new RuntimeException('store outage'));
    Cache::shouldReceive('add')->andThrow(new RuntimeException('store outage'));

    $resultsA = t31RunWorker($taskA);
    $resultsB = t31RunWorker($taskB);

    // Both tenants were dispatched fresh (store produced nothing) and both
    // got complete, successful evidence.
    expect($this->tool->receivedContexts)->toHaveCount(6)
        ->and($resultsA)->toHaveCount(3)
        ->and($resultsB)->toHaveCount(3)
        ->and(array_filter($resultsA, static fn (ProbeSingleResult $r): bool => $r->toolResult->ok))->toHaveCount(3);

    $outcomeA = t31Ingest($this->tenantA, t31Result($this->accessA, $taskA, $resultsA));
    $outcomeB = t31Ingest($this->tenantB, t31Result($this->accessB, $taskB, $resultsB));

    expect($outcomeA->duplicate)->toBeFalse()
        ->and($outcomeB->duplicate)->toBeFalse()
        ->and(ProxyObservation::query()->withoutGlobalScopes()->count())->toBe(2)
        ->and(ProxyHealth::query()->withoutGlobalScopes()->count())->toBe(2);
});

/**
 * Scenario 6 in isolation: the production Laravel-cache-backed metrics
 * counters move as expected (miss → stored → hit) in the array store.
 */
it('moves the production metrics counters for hits misses and stores', function (): void {
    // Swap the recording metrics out for the production binding.
    app()->instance(ProbeCacheMetrics::class, new LaravelCacheProbeCacheMetrics);

    $resultsA = t31RunWorker(t31Deliver($this->tenantA, $this->accessA));
    t31RunWorker(t31Deliver($this->tenantB, $this->accessB));

    expect($resultsA)->toHaveCount(3);

    foreach (['http_measurement', 'marker_result', 'anonymity_header_flags'] as $kind) {
        expect(Cache::get('proxy:probe-cache:metrics:miss:'.$kind))->toBe(1)
            ->and(Cache::get('proxy:probe-cache:metrics:stored:'.$kind))->toBe(1)
            ->and(Cache::get('proxy:probe-cache:metrics:hit:'.$kind))->toBe(1)
            ->and(Cache::get('proxy:probe-cache:metrics:negative_hit:'.$kind))->toBeNull();
    }
});

/**
 * Deterministic ProbeTool double: records every dispatch, outcome driven by
 * a public behaviour closure so tests can inject failures between runs.
 */
final class T31FakeProbeTool implements ProbeTool
{
    /** @var list<ProbeExecutionContext> */
    public array $receivedContexts = [];

    public ?Closure $behaviour = null;

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

        if ($this->behaviour !== null) {
            return ($this->behaviour)($context);
        }

        return ProbeToolResult::ok(
            observations: [
                'status_code' => 200,
                'content_length' => 1284,
                'total_duration_ms' => 610.0,
                'via_header_present' => true,
                'marker_found' => true,
                'anonymous' => true,
            ],
            timingsMs: ['connect' => 12.5],
        );
    }
}

/**
 * In-memory ProbeCacheMetrics recorder for assertions.
 */
final class T31RecordingMetrics implements ProbeCacheMetrics
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

/**
 * One access for one tenant over the SHARED endpoint+credential pair: the
 * canonical identity and the credential fingerprint come out identical for
 * every tenant (R6.2) — that is what makes the cache key cross-tenant.
 */
function t31Access(int $tenantId): ProxyAccess
{
    app(TenantContext::class)->set($tenantId);

    $endpoint = ProxyEndpoint::factory()->create([
        'protocol' => ProxyProtocol::Socks5,
        'host' => '203.0.113.10',
        'port' => 1080,
    ]);

    $credential = ProxyCredential::factory()->create([
        'endpoint_id' => $endpoint->id,
        'kind' => CredentialKind::SocksAuth,
        'username' => 'shared-user',
        'secret' => 'shared-credential-material',
    ]);

    return ProxyAccess::factory()->create([
        'endpoint_id' => $endpoint->id,
        'credential_id' => $credential->id,
    ]);
}

/**
 * Start a manual audit job for the tenant and dispatch its task through the
 * fake delivery queue (T25/T26 delivery path).
 */
function t31Deliver(int $tenantId, ProxyAccess $access): AuditTaskV1
{
    app(TenantContext::class)->set($tenantId);

    $job = app(JobStarter::class)->start(new AuditRequest(
        trigger: AuditTrigger::Manual,
        probeProfile: 'standard',
        accessIds: [$access->id],
        requestedBy: $tenantId,
    ));

    app(DeliveryDispatcher::class)->dispatch($job, [$access]);

    $queue = app(AuditDeliveryQueue::class);

    return $queue->tasks[count($queue->tasks) - 1];
}

/**
 * Worker run (§11.14): every probe spec of the task goes through the
 * container-resolved CacheAwareProbePlanner; the frozen judge set covers the
 * judge-dependent liveness probe.
 *
 * @return list<ProbeSingleResult>
 */
function t31RunWorker(AuditTaskV1 $task): array
{
    $planner = app(CacheAwareProbePlanner::class);
    $judgeSet = t31JudgeSnapshot([t31Judge('j1')]);

    $results = [];

    foreach ($task->probes as $probe) {
        $results = [...$results, ...$planner->run($task, $probe, $judgeSet)];
    }

    return $results;
}

/**
 * Ingest a result under the owning tenant's context (the ingestion lookups
 * are tenant-scoped, INV-006).
 */
function t31Ingest(int $tenantId, AuditResultV1 $result): \BAGArt\ProxyOperations\Audit\IngestionOutcome
{
    app(TenantContext::class)->set($tenantId);

    return app(ResultIngestionService::class)->ingest($result);
}

/**
 * Map the worker results onto the ingestion wire result for the tenant's
 * access: execution failures keep the result Failed, successful liveness
 * evidence travels as probeData (§11.7: cached raw observation feeds the
 * normal evidence pipeline).
 *
 * @param  list<ProbeSingleResult>  $results
 */
function t31Result(ProxyAccess $access, AuditTaskV1 $task, array $results): AuditResultV1
{
    $executionFailures = [];

    foreach ($results as $result) {
        if ($result->toolResult->failure instanceof ExecutionFailure) {
            $executionFailures[] = $result->toolResult->failure;
        }
    }

    $probeData = [];

    foreach ($results as $result) {
        if ($result->probeType === ProbeType::HttpLiveness && $result->toolResult->ok) {
            $probeData['http_liveness'] = [[
                'succeeded' => true,
                'status_code' => (int) ($result->toolResult->observations['status_code'] ?? 0),
                'content_length' => (int) ($result->toolResult->observations['content_length'] ?? 0),
                'total_duration_ms' => (float) ($result->toolResult->observations['total_duration_ms'] ?? 0),
                'via_header_present' => (bool) ($result->toolResult->observations['via_header_present'] ?? false),
                'measured_at' => now()->toIso8601String(),
            ]];

            break;
        }
    }

    return new AuditResultV1(
        taskId: $task->job->taskId,
        attemptId: $task->job->attemptId,
        status: $executionFailures === [] ? AuditResultStatus::Completed : AuditResultStatus::Failed,
        observations: [],
        executionFailures: $executionFailures,
        timings: ['totalMs' => 42],
        checkerNodeId: 'node-1',
        accessId: $access->id,
        probeData: $probeData,
    );
}

function t31Registry(): ToolRegistry
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

/**
 * Rebind the module's ProbeExecutor singleton to the fake tool and drop the
 * planner instance so the next resolution picks up the fresh executor through
 * the provider wiring (cache bindings, key factory, metrics stay from the
 * container; metrics can be overridden with an instance afterwards).
 */
function t31BindWorker(T31FakeProbeTool $tool, T31RecordingMetrics $metrics): void
{
    $registry = t31Registry();

    app()->singleton(ProbeExecutor::class, static function ($app) use ($registry, $tool) {
        return new ProbeExecutor(
            tools: ['socks-checker' => $tool],
            judgeProvider: $app->make(JudgeProvider::class),
            toolRegistry: $registry,
            governor: $app->make(ResourceGovernor::class),
            timeoutFactory: $app->make(ToolTimeoutFactory::class),
        );
    });

    app()->forgetInstance(CacheAwareProbePlanner::class);
    app()->instance(ProbeCacheMetrics::class, $metrics);
}

function t31Judge(string $id): JudgeDescriptor
{
    return new JudgeDescriptor(
        id: $id,
        url: 'https://'.$id.'.example.com/health',
        region: 'us-east',
        protocol: 'https',
        capabilities: array_map(
            static fn (ProbeType $type): string => $type->value,
            ProbeType::cases(),
        ),
        rateLimitPerMinute: 60,
        trustTier: JudgeTrustTier::SelfHosted,
    );
}

function t31JudgeSnapshot(array $judges): JudgeSetSnapshot
{
    return new JudgeSetSnapshot(
        setId: 'snap-1',
        version: 1,
        judges: $judges,
        frozenAt: '2026-08-30T00:00:00Z',
    );
}
