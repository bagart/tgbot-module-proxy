<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Audit\AuditTaskFactory;
use BAGArt\ProxyOperations\Audit\AuditDeadLetter;
use BAGArt\ProxyOperations\Audit\CredentialSealer;
use BAGArt\ProxyOperations\Audit\DeliveryDispatcher;
use BAGArt\ProxyOperations\Audit\DeliveryRetryPolicy;
use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tests\Fixtures\InMemoryAuditDeliveryQueue;

beforeEach(function (): void {
    config()->set([
        'proxy-operations.encryption.kek' => 'proxy-enc-test-kek-v1',
        'proxy-operations.encryption.key_version' => 'k1',
        'proxy-operations.encryption.historical_keks' => [],
        'proxy-operations.audit.delivery.seal_key' => 'proxy-audit-seal-test-key',
    ]);

    $this->tenantId = User::factory()->create()->id;
    app(TenantContext::class)->set($this->tenantId);

    $this->queue = new InMemoryAuditDeliveryQueue;
    $this->dispatcher = new DeliveryDispatcher(
        tasks: new AuditTaskFactory(
            sealer: app(CredentialSealer::class),
            maxAttempts: 3,
            deadlineSeconds: (int) config('proxy-operations.audit.delivery.task_deadline_seconds'),
            probeMaxOutputBytes: (int) config('proxy-operations.audit.delivery.probe_max_output_bytes'),
        ),
        queue: $this->queue,
        retryPolicy: new DeliveryRetryPolicy(maxAttempts: 3),
    );

    $this->job = ProxyAuditJob::factory()->create();
    $this->accesses = ProxyAccess::factory()->count(2)->create()->all();
});

it('delivers a pending job: attempt created, tasks enqueued, statuses transitioned', function (): void {
    $this->dispatcher->dispatch($this->job, $this->accesses);

    $this->job->refresh();

    $attempts = $this->job->attempts()->get();

    expect($attempts)->toHaveCount(1)
        ->and($attempts[0]->attempt_no)->toBe(1)
        ->and($attempts[0]->status)->toBe(AuditAttemptStatus::Delivered)
        ->and($this->job->status)->toBe(AuditJobStatus::Queued)
        ->and($this->job->started_at)->not->toBeNull()
        ->and($this->queue->tasks)->toHaveCount(count($this->accesses));

    foreach ($this->queue->tasks as $task) {
        expect($task->job->jobId)->toBe($this->job->id)
            ->and($task->job->attemptId)->toBe($attempts[0]->id)
            ->and($task->tenantId)->toBe((string) $this->job->tenant_id);
    }
});

it('keeps tenant_id as metadata matching the job tenant', function (): void {
    $this->dispatcher->dispatch($this->job, $this->accesses);

    foreach ($this->queue->tasks as $task) {
        expect((int) $task->tenantId)->toBe($this->tenantId);
    }
});

it('does not create a second attempt on a duplicate dispatch of the same ids', function (): void {
    $this->dispatcher->dispatch($this->job, $this->accesses);
    $this->dispatcher->dispatch($this->job, $this->accesses);

    expect($this->job->attempts()->count())->toBe(1)
        ->and($this->job->attempts()->first()->attempt_no)->toBe(1);
});

it('keeps Postgres consistent and stays retry-safe when the queue is unavailable', function (): void {
    $this->queue->throwOnEnqueue = true;

    try {
        $this->dispatcher->dispatch($this->job, $this->accesses);

        $this->fail('Expected the enqueue failure to propagate.');
    } catch (RuntimeException) {
        // simulated Redis outage
    }

    expect($this->job->refresh()->status)->toBe(AuditJobStatus::Pending)
        ->and($this->job->attempts()->firstOrFail()->status)->toBe(AuditAttemptStatus::Pending)
        ->and($this->queue->tasks)->toHaveCount(0);

    // At-least-once: the retry reuses the same open attempt, no duplicate.
    $this->queue->throwOnEnqueue = false;
    $this->dispatcher->dispatch($this->job, $this->accesses);

    expect($this->job->attempts()->count())->toBe(1)
        ->and($this->job->refresh()->status)->toBe(AuditJobStatus::Queued);
});

it('opens a new attempt on retry while the job identity stays unchanged', function (): void {
    $this->dispatcher->dispatch($this->job, $this->accesses);

    $first = $this->job->attempts()->firstOrFail();
    expect($this->dispatcher->recordFailure($this->job, $first, 'PROBE_TIMEOUT'))->toBeNull();

    $targetSetHash = $this->job->target_set_hash;
    $trigger = $this->job->trigger;
    $snapshotId = $this->job->policy_snapshot_id;

    $this->dispatcher->dispatch($this->job, $this->accesses);

    $this->job->refresh();

    expect($this->job->attempts()->count())->toBe(2)
        ->and($this->job->attempts()->orderBy('attempt_no')->pluck('attempt_no')->all())->toBe([1, 2])
        ->and($this->job->target_set_hash)->toBe($targetSetHash)
        ->and($this->job->trigger)->toBe($trigger)
        ->and($this->job->policy_snapshot_id)->toBe($snapshotId)
        ->and($this->job->status)->toBe(AuditJobStatus::Queued);
});

it('dead-letters and stops delivering once the retry budget is exhausted', function (): void {
    $dispatcher = new DeliveryDispatcher(
        tasks: new AuditTaskFactory(
            sealer: app(CredentialSealer::class),
            maxAttempts: 1,
            deadlineSeconds: 900,
            probeMaxOutputBytes: 1024 * 1024,
        ),
        queue: $this->queue,
        retryPolicy: new DeliveryRetryPolicy(maxAttempts: 1),
    );

    $dispatcher->dispatch($this->job, $this->accesses);

    $entry = $dispatcher->recordFailure($this->job, $this->job->attempts()->firstOrFail(), 'AUTH_FAILURE');

    expect($entry)->toBeInstanceOf(AuditDeadLetter::class)
        ->and($entry->jobId)->toBe($this->job->id)
        ->and($entry->tenantId)->toBe($this->tenantId)
        ->and($entry->reason)->toBe('AUTH_FAILURE')
        ->and($this->job->refresh()->status)->toBe(AuditJobStatus::Failed)
        ->and($this->job->attempts()->firstOrFail()->status)->toBe(AuditAttemptStatus::Failed)
        ->and($this->job->attempts()->firstOrFail()->finished_at)->not->toBeNull();

    $tasksBefore = count($this->queue->tasks);

    // Terminal job — no further deliveries, no further attempts.
    $dispatcher->dispatch($this->job, $this->accesses);

    expect(count($this->queue->tasks))->toBe($tasksBefore)
        ->and($this->job->attempts()->count())->toBe(1);
});
