<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\AuditDeliveryQueue;
use BAGArt\ProxyOperations\Audit\AuditRequest;
use BAGArt\ProxyOperations\Audit\DeliveryDispatcher;
use BAGArt\ProxyOperations\Audit\EventOutboxDispatcher;
use BAGArt\ProxyOperations\Audit\EventTypeRegistry;
use BAGArt\ProxyOperations\Audit\JobStarter;
use BAGArt\ProxyOperations\Audit\ObservationWriter;
use BAGArt\ProxyOperations\Audit\ProbeDataEvidenceExtractor;
use BAGArt\ProxyOperations\Audit\ResultIngestionService;
use BAGArt\ProxyOperations\Audit\DbAuditEventRecorder;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\AuditTrigger;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyEvent;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Models\ProxyObservation;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tests\Fixtures\InMemoryAuditDeliveryQueue;
use BAGArt\ProxyOperations\Tests\Fixtures\RecordingAuditEventConsumer;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use Illuminate\Support\Facades\DB;

/**
 * Stage 5 exit (T28): the full audit pipeline — JobStarter → DeliveryDispatcher
 * (fake queue) → AuditResultV1 → ResultIngestionService with the REAL
 * DimensionalHealthEvaluator + REAL DbAuditEventRecorder → post-commit outbox
 * dispatch — including the failure injections of the T28 spec.
 */
beforeEach(function (): void {
    config()->set([
        'proxy-operations.encryption.kek' => 'proxy-enc-test-kek-v1',
        'proxy-operations.encryption.key_version' => 'k1',
        'proxy-operations.encryption.historical_keks' => [],
        'proxy-operations.audit.delivery.seal_key' => 'proxy-audit-seal-test-key',
    ]);

    $this->tenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($this->tenantId);

    // Fake queue in place of the production Redis Streams binding.
    $this->queue = new InMemoryAuditDeliveryQueue;
    app()->instance(AuditDeliveryQueue::class, $this->queue);

    $this->jobStarter = app(JobStarter::class);
    $this->delivery = app(DeliveryDispatcher::class);

    // Real evaluator + real recorder inside the T26 ingestion unit of work.
    $this->ingestion = new ResultIngestionService(
        evidenceExtractor: new ProbeDataEvidenceExtractor,
        healthEvaluator: app(\BAGArt\ProxyOperations\Audit\HealthEvaluator::class),
        eventRecorder: new DbAuditEventRecorder(app(EventTypeRegistry::class)),
        observationWriter: new ObservationWriter,
    );

    // Post-commit projection consumer — the outbox dispatcher drives it.
    $this->projector = new RecordingAuditEventConsumer;
    $this->outbox = new EventOutboxDispatcher([$this->projector]);
});

/**
 * Full positive run for one access: success probeData through the queue →
 * ingestion → observation + health + terminal statuses + audit.completed in
 * the outbox (one transaction), then post-commit dispatch to the projector.
 */
it('runs the full success pipeline: job → task → result → ingestion → events → post-commit projection', function (): void {
    $access = ProxyAccess::factory()->create();

    $job = $this->jobStarter->start(new AuditRequest(
        trigger: AuditTrigger::Manual,
        probeProfile: 'standard',
        accessIds: [$access->id],
        requestedBy: $this->tenantId,
    ));

    expect($job->status)->toBe(AuditJobStatus::Pending);

    $this->delivery->dispatch($job, [$access]);

    $task = $this->queue->tasks[0];

    $result = new AuditResultV1(
        taskId: $task->job->taskId,
        attemptId: $task->job->attemptId,
        status: AuditResultStatus::Completed,
        observations: [],
        executionFailures: [],
        timings: ['totalMs' => 750],
        checkerNodeId: 'checker-node-1',
        accessId: $access->id,
        probeData: [
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
        ],
    );

    $outcome = $this->ingestion->ingest($result);

    // The ingestion wrote observation, health projection and terminal
    // statuses; the event row sits in the outbox table.
    $observation = ProxyObservation::query()->findOrFail($outcome->observationId);

    expect($outcome->duplicate)->toBeFalse()
        ->and($observation->access_id)->toBe($access->id)
        ->and($observation->outcome)->toBe('success')
        ->and(ProxyHealth::query()->where('access_id', $access->id)->count())->toBe(1)
        ->and(attemptRefresh($task)->status)->toBe(AuditAttemptStatus::Completed)
        ->and($job->refresh()->status)->toBe(AuditJobStatus::Completed)
        ->and(ProxyEvent::query()->where('aggregate_id', $access->id)->count())->toBe(1);

    $event = ProxyEvent::query()->where('aggregate_id', $access->id)->firstOrFail();

    expect($event->event_type)->toBe('audit.completed')
        ->and($event->dispatch_status)->toBe(ProxyEvent::STATUS_PENDING)
        ->and($event->payload['observationId'])->toBe($observation->id)
        ->and($event->sequence)->toBe(1);

    // Post-commit only: dispatch hands it to the projector after COMMIT.
    expect($this->outbox->dispatch())->toBe(1);

    accessRefresh($access);

    expect($this->projector->handledEventIds())->toBe([$event->id])
        ->and($this->projector->transactionLevels[$event->id])->toBe(DB::transactionLevel());
});

/**
 * Access state transition through the REAL evaluator: a WORKING access hit by
 * two consecutive TCP_TIMEOUT results steps Working → Degraded through the
 * canonical ladder (New never escalates on failures — it climbs on recovery
 * only), and every event lands in the outbox in per-tenant sequence order.
 */
it('transitions access state through the real health evaluator and orders the outbox per tenant', function (): void {
    $access = ProxyAccess::factory()->working()->create();

    $job = $this->jobStarter->start(new AuditRequest(
        trigger: AuditTrigger::Manual,
        probeProfile: 'standard',
        accessIds: [$access->id],
        requestedBy: $this->tenantId,
    ));

    $this->delivery->dispatch($job, [$access]);

    $task = $this->queue->tasks[0];

    $failureResult = fn (): AuditResultV1 => new AuditResultV1(
        taskId: $task->job->taskId,
        attemptId: $task->job->attemptId,
        status: AuditResultStatus::Failed,
        observations: [
            new ProxyFailure((new FailureTaxonomy)->descriptor(FailureCode::TcpTimeout), ['latency_ms' => 1500]),
        ],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'checker-node-1',
        accessId: $access->id,
    );

    $first = $this->ingestion->ingest($failureResult());
    expect($first->stateTransition)->toBeNull() // one failure is below the threshold
        ->and($access->refresh()->consecutive_failures)->toBe(1);

    $second = $this->ingestion->ingest($failureResult());

    expect($second->stateTransition)->toBe(AccessState::Degraded)
        ->and($access->refresh()->state)->toBe(AccessState::Degraded);

    // Outbox rows follow the ingestion order with a per-tenant monotonic
    // sequence, and the post-commit projector sees exactly that order.
    $sequences = ProxyEvent::query()->orderBy('sequence')->pluck('sequence')->all();

    expect($sequences)->toBe([1, 2])
        ->and(ProxyEvent::query()->where('sequence', 2)->firstOrFail()->payload['stateTransition'])->toBe('degraded');

    $this->outbox->dispatch();

    expect($this->projector->handledEventIds())->toHaveCount(2);
});

/**
 * Failure injection: a worker crash (execution failures only) — no proxy
 * observation, no health change, attempt and job terminal-failed, and the
 * operational worker.failed event in the outbox.
 */
it('fails the job and raises worker.failed when the worker crashes without touching proxy health', function (): void {
    $access = ProxyAccess::factory()->create();

    $job = $this->jobStarter->start(new AuditRequest(
        trigger: AuditTrigger::Manual,
        probeProfile: 'standard',
        accessIds: [$access->id],
        requestedBy: $this->tenantId,
    ));

    $this->delivery->dispatch($job, [$access]);
    $task = $this->queue->tasks[0];

    $crash = new AuditResultV1(
        taskId: $task->job->taskId,
        attemptId: $task->job->attemptId,
        status: AuditResultStatus::Failed,
        observations: [],
        executionFailures: [
            new ExecutionFailure((new FailureTaxonomy)->descriptor(FailureCode::ToolCrash), ['exit_code' => 137]),
        ],
        timings: [],
        checkerNodeId: 'checker-node-1',
        accessId: $access->id,
    );

    $outcome = $this->ingestion->ingest($crash);

    expect($outcome->duplicate)->toBeFalse()
        ->and($outcome->observationId)->toBeNull()
        ->and(ProxyObservation::query()->where('access_id', $access->id)->count())->toBe(0)
        ->and(ProxyHealth::query()->where('access_id', $access->id)->count())->toBe(0)
        ->and(attemptRefresh($task)->status)->toBe(AuditAttemptStatus::Failed)
        ->and($job->refresh()->status)->toBe(AuditJobStatus::Failed)
        ->and(accessRefresh($access)->state)->toBe(AccessState::New);

    $event = ProxyEvent::query()->firstOrFail();

    expect($event->event_type)->toBe('worker.failed')
        ->and($event->aggregate_type)->toBe('proxy_audit_attempt')
        ->and($event->aggregate_id)->toBe($task->job->attemptId)
        ->and($event->payload['executionFailures'][0]['code'])->toBe('TOOL_CRASH');

    expect($this->outbox->dispatch())->toBe(1)
        ->and(ProxyEvent::query()->firstOrFail()->dispatch_status)->toBe(ProxyEvent::STATUS_DISPATCHED);
});

/**
 * Failure injection: duplicate result delivery — exactly one observation, one
 * event; and a throwing consumer cannot lose data — the event is retried on
 * the next pass and the deduping consumer handles it exactly once.
 */
it('dedups duplicate deliveries and survives consumer failure without data loss', function (): void {
    $access = ProxyAccess::factory()->create();

    $job = $this->jobStarter->start(new AuditRequest(
        trigger: AuditTrigger::Manual,
        probeProfile: 'standard',
        accessIds: [$access->id],
        requestedBy: $this->tenantId,
    ));

    $this->delivery->dispatch($job, [$access]);
    $task = $this->queue->tasks[0];

    $result = new AuditResultV1(
        taskId: $task->job->taskId,
        attemptId: $task->job->attemptId,
        status: AuditResultStatus::Completed,
        observations: [],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'checker-node-1',
        accessId: $access->id,
    );

    $first = $this->ingestion->ingest($result);
    $second = $this->ingestion->ingest($result);

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and(ProxyObservation::query()->count())->toBe(1)
        ->and(ProxyEvent::query()->count())->toBe(1);

    // Consumer outage: the event goes to failed, nothing is lost...
    $this->projector->throwOnNextHandle = true;
    $this->outbox->dispatch();

    expect(ProxyEvent::query()->firstOrFail()->dispatch_status)->toBe(ProxyEvent::STATUS_FAILED)
        ->and($this->projector->handledEventIds())->toBe([]);

    // ...the retry redelivers and the consumer dedups by event_id.
    expect($this->outbox->dispatch())->toBe(1)
        ->and(ProxyEvent::query()->firstOrFail()->dispatch_status)->toBe(ProxyEvent::STATUS_DISPATCHED)
        ->and(ProxyEvent::query()->firstOrFail()->attempt_count)->toBe(2)
        ->and($this->projector->handledEventIds())->toHaveCount(1);
});

function attemptRefresh(AuditTaskV1 $task): ProxyAuditAttempt
{
    return ProxyAuditAttempt::query()->findOrFail($task->job->attemptId);
}

function accessRefresh(ProxyAccess $access): ProxyAccess
{
    $access->refresh();

    return $access;
}
