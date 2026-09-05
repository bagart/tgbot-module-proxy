<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use InvalidArgumentException;

/**
 * Per-attempt delivery retry budget (plan §11.27, §11.37 R6.5): a small
 * budget over attempt_no. Budget exhaustion produces a dead-letter entry; the
 * 24h→72h scheduler reschedule backoff stays out of scope here — it belongs to
 * the Scheduler, not to delivery (§11.27).
 *
 * Deviation (documented in T25): the budget currently resolves from
 * `config('proxy-operations.audit.delivery.retry')` — the AuditPolicySnapshot
 * DTO does not carry a retryPolicy section yet.
 */
final readonly class DeliveryRetryPolicy
{
    public function __construct(
        private readonly int $maxAttempts,
    ) {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('The delivery retry budget must allow at least one attempt.');
        }
    }

    public function canRetry(ProxyAuditAttempt $attempt): bool
    {
        return $attempt->attempt_no < $this->maxAttempts;
    }

    public function deadLetter(ProxyAuditJob $job, ProxyAuditAttempt $attempt, string $reason): AuditDeadLetter
    {
        return new AuditDeadLetter(
            jobId: $job->id,
            attemptId: $attempt->id,
            tenantId: (int) $job->tenant_id,
            reason: $reason,
            deadLetteredAt: now()->toIso8601String(),
        );
    }
}
