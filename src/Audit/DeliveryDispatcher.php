<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bridges Postgres to the checker worker (plan §§11.9, 11.19, 11.21): for a
 * dispatchable job it creates one attempt (attempt_no = max + 1), builds one
 * wire task per access, and enqueues them. The Postgres part runs in a
 * transaction; enqueue happens strictly after commit (at-least-once — the
 * consumer dedups by task_id + attempt_id, §11.19).
 *
 * Idempotent re-dispatch: an attempt that is still open (pending/delivered/
 * running) is reused, never duplicated — the (job_id, attempt_no) unique
 * constraint is the DB-side idempotency gate. Delivery itself has no domain
 * side effects beyond the status stamps (§11.37 R6.7).
 */
final class DeliveryDispatcher
{
    public function __construct(
        private readonly AuditTaskFactory $tasks,
        private readonly AuditDeliveryQueue $queue,
        private readonly DeliveryRetryPolicy $retryPolicy,
    ) {
    }

    /**
     * @param  list<ProxyAccess>  $accesses  The job's target set, resolved by
     *                                        the caller within tenant scope.
     *
     * @throws RuntimeException When enqueueing fails after the attempt row committed.
     */
    public function dispatch(ProxyAuditJob $job, array $accesses): void
    {
        if (in_array($job->status, [AuditJobStatus::Completed, AuditJobStatus::Failed, AuditJobStatus::Cancelled], true)) {
            return; // Terminal job — never deliver again.
        }

        if ($accesses === []) {
            return;
        }

        $attempt = DB::transaction(function () use ($job): ProxyAuditAttempt {
            $open = $job->attempts()
                ->whereIn('status', [AuditAttemptStatus::Pending->value, AuditAttemptStatus::Delivered->value, AuditAttemptStatus::Running->value])
                ->first();

            if ($open !== null) {
                return $open;
            }

            return ProxyAuditAttempt::query()->create([
                'job_id' => $job->id,
                'attempt_no' => (int) $job->attempts()->max('attempt_no') + 1,
                'status' => AuditAttemptStatus::Pending,
            ]);
        });

        // Post-commit enqueue (at-least-once): a failure here leaves the
        // attempt Pending, so the next dispatch reuses the same attempt row
        // and Postgres state stays consistent.
        foreach ($accesses as $access) {
            $this->queue->enqueue($this->tasks->build($job, $attempt, $access));
        }

        $attempt->forceFill(['status' => AuditAttemptStatus::Delivered])->save();

        $job->forceFill([
            'status' => AuditJobStatus::Queued,
            'started_at' => $job->started_at ?? now(),
        ])->save();
    }

    /**
     * Records a failed attempt (§11.27): within budget → nothing further (the
     * next dispatch opens a new attempt on the unchanged job); budget
     * exhausted → dead-letter entry, attempt `failed`, job `failed` — no
     * further deliveries.
     */
    public function recordFailure(ProxyAuditJob $job, ProxyAuditAttempt $attempt, string $reason): ?AuditDeadLetter
    {
        if ($attempt->job_id !== $job->id) {
            throw new InvalidArgumentException('The attempt does not belong to the given job.');
        }

        $attempt->forceFill([
            'status' => AuditAttemptStatus::Failed,
            'result_code' => mb_substr($reason, 0, 255),
            'finished_at' => now(),
        ])->save();

        if ($this->retryPolicy->canRetry($attempt)) {
            return null;
        }

        $entry = $this->retryPolicy->deadLetter($job, $attempt, $reason);

        $job->forceFill([
            'status' => AuditJobStatus::Failed,
            'result_code' => mb_substr($reason, 0, 255),
            'completed_at' => now(),
        ])->save();

        return $entry;
    }
}
