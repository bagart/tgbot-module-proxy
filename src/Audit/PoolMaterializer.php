<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\VerifiedEligibilityCheck;
use BAGArt\ProxyOperations\Domain\Evidence\VerifiedEligibilityPolicy;
use BAGArt\ProxyOperations\Domain\Pool\PoolCandidateView;
use BAGArt\ProxyOperations\Domain\Pool\PoolKind;
use BAGArt\ProxyOperations\Domain\Pool\PoolMaterializationResult;
use BAGArt\ProxyOperations\Domain\Pool\SelectionReasonCode;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Models\ProxyPoolDecision;
use BAGArt\ProxyOperations\Models\ProxyPoolMember;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Rebuilds a DYNAMIC/HYBRID pool from its predicate (plan §11.25): candidate
 * query → per-candidate decision → atomic member projection replacement.
 * The run is reproducible (same inputs + policy version → same members) and
 * every decision is logged. HYBRID hand-picked rows
 * (materialization_version NULL) survive; dynamic rows are replaced.
 */
final class PoolMaterializer
{
    public function __construct(
        private readonly VerifiedEligibilityPolicy $eligibility,
        private readonly AuditEventRecorder $events,
        private readonly int $maxMembers = 10000,
    ) {
    }

    /**
     * @throws InvalidArgumentException On STATIC pools or an invalid predicate.
     * @throws RuntimeException         When the candidate set exceeds the cap
     *                                  (nothing is materialized).
     */
    public function materialize(ProxyPool $pool, int $policyVersion): PoolMaterializationResult
    {
        if ($pool->kind === PoolKind::Static) {
            throw new InvalidArgumentException('Static pools have no predicate to materialize.');
        }

        $predicate = $pool->predicateDto();

        if ($predicate === null) {
            throw new InvalidArgumentException('Dynamic and hybrid pools require a predicate.');
        }

        $materializationId = self::newRunId();
        $generatedAt = Carbon::now();

        $healths = ProxyHealth::query()
            ->where('tenant_id', $pool->tenant_id)
            ->get()
            ->keyBy('access_id');

        $candidates = ProxyAccess::query()->with('endpoint')->get();
        $members = $pool->members->keyBy('access_id');

        if ($candidates->count() > $this->maxMembers) {
            throw new RuntimeException('Candidate set exceeds the materialization cap; run aborted.');
        }

        $accepted = [];
        $skipped = 0;
        $decisions = [];

        // Monotonic run version stamped on every dynamic member row this run
        // produces (reproducibility marker, plan §11.25).
        $runVersion = (int) ProxyPoolMember::query()->where('pool_id', $pool->id)->max('materialization_version') + 1;

        foreach ($candidates as $access) {
            $view = PoolCandidateView::fromModels($access, $healths->get($access->id));

            $reason = $predicate->firstMismatch($view);

            if ($reason !== null) {
                $skipped++;
                $decisions[] = self::decisionRow($pool, $materializationId, $access->id, $reason, $view->healthScore, $policyVersion, $generatedAt);

                continue;
            }

            if (! $this->eligibility->isEligible(self::eligibilityCheck($access, $healths->get($access->id)))) {
                $skipped++;
                $decisions[] = self::decisionRow($pool, $materializationId, $access->id, SelectionReasonCode::NotEligible, $view->healthScore, $policyVersion, $generatedAt);

                continue;
            }

            if ($members->has($access->id) && $members->get($access->id)->materialization_version === null) {
                // Hand-picked member already present — keep it, log a skip
                // for the dynamic slot (HYBRID preservation).
                $skipped++;
                $decisions[] = self::decisionRow($pool, $materializationId, $access->id, SelectionReasonCode::AlreadyMember, $view->healthScore, $policyVersion, $generatedAt);

                continue;
            }

            $accepted[] = $access->id;
            $decisions[] = self::decisionRow($pool, $materializationId, $access->id, null, $view->healthScore, $policyVersion, $generatedAt);
        }

        // One transaction: replace the dynamic projection + log decisions.
        // Hand-picked rows (materialization_version NULL) survive.
        $pool->getConnection()->transaction(function () use ($pool, $materializationId, $accepted, $decisions, $runVersion, $generatedAt, $policyVersion): void {
            ProxyPoolMember::query()
                ->where('pool_id', $pool->id)
                ->whereNotNull('materialization_version')
                ->delete();

            foreach ($accepted as $accessId) {
                ProxyPoolMember::query()->updateOrCreate(
                    ['pool_id' => $pool->id, 'access_id' => $accessId],
                    ['added_at' => $generatedAt, 'materialization_version' => $runVersion],
                );
            }

            ProxyPoolDecision::query()->insert($decisions);

            $pool->forceFill([
                'last_materialization_id' => $materializationId,
                'last_materialized_at' => $generatedAt,
                'policy_version' => $policyVersion,
            ])->save();
        });

        $this->events->record(new EventEnvelope(
            eventId: EventEnvelope::generateId(),
            eventType: 'pool.rebuilt',
            occurredAt: $generatedAt->toIso8601String(),
            tenantId: (string) $pool->tenant_id,
            aggregateRef: $pool->id,
            payload: [
                'materializationId' => $materializationId,
                'accepted' => count($accepted),
                'skipped' => $skipped,
                'policyVersion' => $policyVersion,
            ],
        ));

        return new PoolMaterializationResult(
            materializationId: $materializationId,
            poolId: $pool->id,
            accepted: count($accepted),
            skipped: $skipped,
            generatedAt: $generatedAt,
            policyVersion: $policyVersion,
        );
    }

    private static function newRunId(): string
    {
        return 'mat-'.bin2hex(random_bytes(12));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decisionRow(ProxyPool $pool, string $runId, ?string $accessId, ?SelectionReasonCode $reason, ?int $score, int $policyVersion, Carbon $at): array
    {
        return [
            'id' => self::uuid(),
            'tenant_id' => $pool->tenant_id,
            'pool_id' => $pool->id,
            'materialization_id' => $runId,
            'access_id' => $accessId,
            'decision' => $reason === null ? 'accepted' : 'skipped',
            'reason_code' => ($reason ?? SelectionReasonCode::Accepted)->value,
            'score' => $score,
            'policy_version' => $policyVersion,
            'created_at' => $at->toDateTimeString(),
        ];
    }

    private static function eligibilityCheck(\BAGArt\ProxyOperations\Models\ProxyAccess $access, ?ProxyHealth $health): VerifiedEligibilityCheck
    {
        $satisfied = [];
        $signals = $health?->dimension_signals ?? [];

        foreach ($signals as $dimension => $signal) {
            $type = EvidenceType::tryFrom((string) $dimension);

            if ($type !== null && $signal === 'pass') {
                $satisfied[] = $type;
            }
        }

        $endpoint = $access->endpoint;

        return new VerifiedEligibilityCheck(
            protocol: $endpoint?->protocol ?? $access->endpoint()->firstOrFail()->protocol,
            satisfiedDimensions: $satisfied,
            lastTelegramCheckUsable: $access->telegram_usable === true,
            telegramCheckedAt: $access->telegram_checked_at?->toImmutable(),
            now: Carbon::now()->toImmutable(),
        );
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}
