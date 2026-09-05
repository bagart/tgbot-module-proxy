<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Application-layer ingestion of one AuditResultV1 (plan §§11.9, 11.19,
 * §11.35 пп.1,18): idempotency by attempt state (worker key task_id +
 * attempt_id), tenant-checked row resolution, evidence extraction from the
 * OD-5 probeData, and the single transactional unit of work — Observation
 * insert → attempt/job status update → health evaluation + lifecycle
 * transition → event append. Postgres is written exclusively here; the
 * worker never touches it. Outbox dispatch happens strictly after commit
 * (T28's EventOutboxDispatcher, at-least-once).
 */
final class ResultIngestionService
{
    public function __construct(
        private readonly ProbeDataEvidenceExtractor $evidenceExtractor,
        private readonly HealthEvaluator $healthEvaluator,
        private readonly AuditEventRecorder $eventRecorder,
        private readonly ObservationWriter $observationWriter,
    ) {}

    /**
     * @throws ForeignAccessIdException When attempt/access do not resolve
     *                                  within the current tenant scope (the
     *                                  tenant_id on the wire result is
     *                                  metadata; authorization comes from
     *                                  the DB rows, INV-006).
     * @throws RuntimeException         When the result references an unknown
     *                                  attempt, or the access id is missing.
     * @throws InvalidArgumentException When the result carries no access id.
     */
    public function ingest(AuditResultV1 $result): IngestionOutcome
    {
        $attempt = ProxyAuditAttempt::query()->whereKey($result->attemptId)->first();

        if ($attempt === null) {
            // Tenant-scoped lookup: unknown id OR foreign tenant — fail
            // closed, nothing written either way.
            throw new ForeignAccessIdException("Audit result references an unknown or foreign attempt {$result->attemptId}.");
        }

        if ($attempt->status === AuditAttemptStatus::Completed) {
            // Duplicate delivery of the same task_id + attempt_id (§11.19):
            // the attempt row already carries the terminal outcome.
            return IngestionOutcome::duplicate();
        }

        if ($result->accessId === '') {
            throw new InvalidArgumentException('Audit result carries no access id.');
        }

        $job = $attempt->job()->first();

        if ($job === null) {
            throw new ForeignAccessIdException("Audit attempt {$attempt->id} resolves to no job within the current tenant scope.");
        }

        $access = ProxyAccess::query()->whereKey($result->accessId)->first();

        if ($access === null) {
            throw new ForeignAccessIdException("Audit result references an unknown or foreign access {$result->accessId}.");
        }

        $protocol = $access->endpoint()->firstOrFail()->identity()->protocol;

        $evidence = [
            ...$this->evidenceExtractor->extract($result->probeData, $protocol),
            ...$this->evidenceExtractor->extractFailures($result->observations),
        ];

        /** @var IngestionOutcome $outcome */
        $outcome = DB::transaction(function () use ($result, $attempt, $job, $access, $evidence): IngestionOutcome {
            // INV-014/015: checker-infrastructure faults never become proxy
            // observations and never touch proxy health — they fail the attempt
            // and raise the operational worker.failed event only.
            $crashedWorker = $result->executionFailures !== [];

            $observation = $crashedWorker
                ? null
                : $this->observationWriter->write($job, $result, $evidence);

            $completed = $result->status === AuditResultStatus::Completed && ! $crashedWorker;

            $attempt->forceFill([
                'status' => $completed ? AuditAttemptStatus::Completed : AuditAttemptStatus::Failed,
                'result_code' => self::resultCode($result),
                'finished_at' => now(),
            ])->save();

            if ($completed) {
                $job->forceFill([
                    'status' => AuditJobStatus::Completed,
                    'completed_at' => $job->completed_at ?? now(),
                ])->save();
            }

            if ($crashedWorker) {
                // A crashed worker does not retry in place: the job terminates
                // failed; a new job re-audits the target set.
                $job->forceFill([
                    'status' => AuditJobStatus::Failed,
                    'result_code' => self::resultCode($result),
                    'completed_at' => $job->completed_at ?? now(),
                ])->save();

                $this->eventRecorder->record(new EventEnvelope(
                    eventId: EventEnvelope::generateId(),
                    eventType: 'worker.failed',
                    occurredAt: now()->toIso8601String(),
                    tenantId: (string) $job->tenant_id,
                    aggregateRef: $attempt->id,
                    payload: [
                        'taskId' => $result->taskId,
                        'attemptId' => $result->attemptId,
                        'accessId' => $access->id,
                        'executionFailures' => array_map(
                            static fn ($failure): array => [
                                'code' => $failure->descriptor()->code->value,
                                'class' => $failure->descriptor()->class->value,
                            ],
                            $result->executionFailures,
                        ),
                    ],
                ));

                return IngestionOutcome::ingested(null, null);
            }

            $evaluation = $this->healthEvaluator->evaluate($access, $evidence);

            $this->eventRecorder->record(new EventEnvelope(
                eventId: EventEnvelope::generateId(),
                eventType: 'audit.completed',
                occurredAt: now()->toIso8601String(),
                tenantId: (string) $job->tenant_id,
                aggregateRef: $access->id,
                payload: [
                    'taskId' => $result->taskId,
                    'attemptId' => $result->attemptId,
                    'accessId' => $access->id,
                    'observationId' => $observation->id,
                    'status' => $result->status->value,
                    'stateTransition' => $evaluation->stateAfter?->value,
                ],
            ));

            return IngestionOutcome::ingested($observation->id, $evaluation->stateAfter);
        });

        return $outcome;
    }

    private static function resultCode(AuditResultV1 $result): string
    {
        if ($result->observations !== []) {
            return mb_substr($result->observations[0]->descriptor->code->value, 0, 255);
        }

        if ($result->executionFailures !== []) {
            return mb_substr($result->executionFailures[0]->descriptor()->code->value, 0, 255);
        }

        return mb_substr($result->status->value.';'.Str::limit($result->checkerNodeId, 100), 0, 255);
    }
}
