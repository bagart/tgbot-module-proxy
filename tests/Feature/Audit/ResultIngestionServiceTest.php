<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\ForeignAccessIdException;
use BAGArt\ProxyOperations\Audit\ObservationWriter;
use BAGArt\ProxyOperations\Audit\ProbeDataEvidenceExtractor;
use BAGArt\ProxyOperations\Audit\ResultIngestionService;
use BAGArt\ProxyOperations\Domain\Evidence\BandwidthEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\HttpEvidence;
use BAGArt\ProxyOperations\Domain\Evidence\TcpEvidence;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyObservation;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tests\Fixtures\CapturingAuditEventRecorder;
use BAGArt\ProxyOperations\Tests\Fixtures\CapturingHealthEvaluator;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($this->tenantId);

    $this->job = ProxyAuditJob::factory()->create();
    $this->access = ProxyAccess::factory()->create();
    $this->attempt = ProxyAuditAttempt::factory()->create([
        'job_id' => $this->job->id,
        'attempt_no' => 1,
        'status' => AuditAttemptStatus::Delivered,
    ]);

    $this->evaluator = new CapturingHealthEvaluator;
    $this->recorder = new CapturingAuditEventRecorder;
    $this->service = new ResultIngestionService(
        evidenceExtractor: new ProbeDataEvidenceExtractor,
        healthEvaluator: $this->evaluator,
        eventRecorder: $this->recorder,
        observationWriter: new ObservationWriter,
    );
});

function successfulProbeData(): array
{
    return [
        'http_liveness' => [
            [
                'succeeded' => true,
                'status_code' => 200,
                'content_length' => 1284,
                'total_duration_ms' => 610.0,
                'via_header_present' => true,
                'measured_at' => '2026-08-30T10:00:00+00:00',
            ],
        ],
        'latency_series' => [
            ['latency_ms' => 120.5, 'bytes_transferred' => 1024],
        ],
    ];
}

function successfulResult(ProxyAccess $access, ProxyAuditAttempt $attempt): AuditResultV1
{
    return new AuditResultV1(
        taskId: 'task-1',
        attemptId: $attempt->id,
        status: AuditResultStatus::Completed,
        observations: [],
        executionFailures: [],
        timings: ['totalMs' => 900],
        checkerNodeId: 'checker-node-1',
        accessId: $access->id,
        probeData: successfulProbeData(),
    );
}

it('ingests a successful result: observation row, completed attempt/job, evidence to the evaluator', function (): void {
    $outcome = $this->service->ingest(successfulResult($this->access, $this->attempt));

    expect($outcome->duplicate)->toBeFalse()
        ->and($outcome->observationId)->toBeString()->not->toBeNull()
        ->and($outcome->stateTransition)->toBeNull();

    $observation = ProxyObservation::query()->findOrFail($outcome->observationId);

    expect($observation->access_id)->toBe($this->access->id)
        ->and($observation->tenant_id)->toBe($this->tenantId)
        ->and($observation->outcome)->toBe('success')
        ->and($observation->failure_code)->toBeNull()
        ->and($observation->policy_snapshot_id)->toBe($this->job->policy_snapshot_id)
        ->and($observation->probe_profile_version)->toBe((string) $this->job->policySnapshot->policy_version)
        ->and($observation->checker_node_id)->toBe('checker-node-1')
        ->and($observation->probe_type->value)->toBe('http_liveness');

    $this->attempt->refresh();
    $this->job->refresh();

    expect($this->attempt->status)->toBe(AuditAttemptStatus::Completed)
        ->and($this->attempt->finished_at)->not->toBeNull()
        ->and($this->job->status)->toBe(AuditJobStatus::Completed)
        ->and($this->job->completed_at)->not->toBeNull();

    // Evidence passed to the evaluator: HTTP + bandwidth records extracted
    // from probeData.
    expect($this->evaluator->calls)->toHaveCount(1)
        ->and($this->evaluator->calls[0]['accessId'])->toBe($this->access->id)
        ->and($this->evaluator->calls[0]['evidence'][0])->toBeInstanceOf(HttpEvidence::class)
        ->and($this->evaluator->calls[0]['evidence'][1])->toBeInstanceOf(BandwidthEvidence::class);
});

it('appends evaluator calls and events inside the ingestion transaction', function (): void {
    // RefreshDatabase wraps each test in one outer transaction; the
    // ingestion transaction opens exactly one level deeper and closes it.
    $outerLevel = DB::transactionLevel();

    $outcome = $this->service->ingest(successfulResult($this->access, $this->attempt));

    expect(DB::transactionLevel())->toBe($outerLevel) // transaction closed cleanly
        ->and($this->evaluator->calls[0]['transactionLevel'])->toBe($outerLevel + 1)
        ->and($this->recorder->records)->toHaveCount(1)
        ->and($this->recorder->records[0]['transactionLevel'])->toBe($outerLevel + 1);

    $envelope = $this->recorder->records[0]['envelope'];

    expect($envelope->eventType)->toBe('audit.completed')
        ->and($envelope->tenantId)->toBe((string) $this->tenantId)
        ->and($envelope->aggregateRef)->toBe($this->access->id)
        ->and($envelope->payload['observationId'])->toBe($outcome->observationId)
        ->and($envelope->payload['taskId'])->toBe('task-1');
});

it('treats a redelivery for an already completed attempt as a duplicate with zero writes', function (): void {
    $first = $this->service->ingest(successfulResult($this->access, $this->attempt));
    expect($first->duplicate)->toBeFalse();

    $second = $this->service->ingest(successfulResult($this->access, $this->attempt));

    expect($second->duplicate)->toBeTrue()
        ->and($second->observationId)->toBeNull()
        ->and(ProxyObservation::query()->count())->toBe(1)
        ->and($this->evaluator->calls)->toHaveCount(1)
        ->and($this->recorder->records)->toHaveCount(1);
});

it('ingests failure-only results: failure observation plus failure evidence to the evaluator', function (): void {
    $result = new AuditResultV1(
        taskId: 'task-2',
        attemptId: $this->attempt->id,
        status: AuditResultStatus::Failed,
        observations: [
            new ProxyFailure(
                (new FailureTaxonomy)->descriptor(FailureCode::TcpTimeout),
                ['latency_ms' => 1500],
            ),
        ],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'checker-node-1',
        accessId: $this->access->id,
    );

    $outcome = $this->service->ingest($result);

    expect($outcome->duplicate)->toBeFalse();

    $observation = ProxyObservation::query()->findOrFail($outcome->observationId);

    expect($observation->outcome)->toBe('failure')
        ->and($observation->failure_code)->toBe(FailureCode::TcpTimeout)
        ->and($observation->failure_class->value)->toBe('proxy')
        ->and($observation->evidence['failures']['failure_0']['code'])->toBe('TCP_TIMEOUT');

    $this->attempt->refresh();

    expect($this->attempt->status)->toBe(AuditAttemptStatus::Failed)
        ->and($this->attempt->result_code)->toBe('TCP_TIMEOUT')
        ->and($this->job->refresh()->status)->toBe(AuditJobStatus::Pending); // job stays open for retries

    // §11.7: failure evidence counts — the evaluator is still invoked.
    expect($this->evaluator->calls)->toHaveCount(1)
        ->and($this->evaluator->calls[0]['evidence'][0])->toBeInstanceOf(TcpEvidence::class)
        ->and($this->evaluator->calls[0]['evidence'][0]->connectSucceeded)->toBeFalse();
});

it('rejects a result referencing a foreign-tenant attempt without writing anything', function (): void {
    $otherTenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($otherTenantId);

    $this->service->ingest(successfulResult($this->access, $this->attempt));
})->throws(ForeignAccessIdException::class);

it('leaves no writes behind when a foreign-tenant result is rejected', function (): void {
    $otherTenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($otherTenantId);

    try {
        $this->service->ingest(successfulResult($this->access, $this->attempt));
    } catch (ForeignAccessIdException) {
        // expected
    }

    expect(ProxyObservation::query()->count())->toBe(0)
        ->and($this->attempt->refresh()->status)->toBe(AuditAttemptStatus::Delivered)
        ->and($this->evaluator->calls)->toBe([])
        ->and($this->recorder->records)->toBe([]);
});

it('round-trips a result with probeData through JSON', function (): void {
    $result = successfulResult($this->access, $this->attempt);

    expect(AuditResultV1::SCHEMA_VERSION)->toBe(1)
        ->and(AuditResultV1::fromJson($result->jsonSerialize()))->toEqual($result);
});

it('parses legacy payloads without probeData and accessId (backward compatible)', function (): void {
    $legacy = [
        'taskId' => 'task-legacy',
        'attemptId' => $this->attempt->id,
        'status' => 'completed',
        'observations' => [],
        'executionFailures' => [],
        'timings' => [],
        'checkerNodeId' => 'checker-node-1',
        'schemaVersion' => 1,
    ];

    $parsed = AuditResultV1::fromJson($legacy);

    expect($parsed->probeData)->toBe([])
        ->and($parsed->accessId)->toBe('')
        ->and($parsed->taskId)->toBe('task-legacy');
});

it('fails closed on probeData carrying tenant interpretation or secret keys', function (): void {
    new AuditResultV1(
        taskId: 'task-1',
        attemptId: $this->attempt->id,
        status: AuditResultStatus::Completed,
        observations: [],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'node-1',
        accessId: $this->access->id,
        probeData: ['http_liveness' => [['anonymity_tier' => 'elite']]],
    );
})->throws(InvalidArgumentException::class, 'interpretation');

it('fails closed on probeData carrying a proxy auth header', function (): void {
    new AuditResultV1(
        taskId: 'task-1',
        attemptId: $this->attempt->id,
        status: AuditResultStatus::Completed,
        observations: [],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'node-1',
        accessId: $this->access->id,
        probeData: ['http_liveness' => [['proxy_authorization' => 'Basic xxx']]],
    );
})->throws(InvalidArgumentException::class, 'secret');
