<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Evidence\VerifiedEligibilityCheck;
use BAGArt\ProxyOperations\Domain\Evidence\VerifiedEligibilityPolicy;
use BAGArt\ProxyOperations\Domain\Lease\ProxyLeaseDto;
use BAGArt\ProxyOperations\Domain\Lease\ScoredCandidate;
use BAGArt\ProxyOperations\Domain\Lease\SelectionCriteria;
use BAGArt\ProxyOperations\Domain\Lease\SelectionDecision;
use BAGArt\ProxyOperations\Domain\Lease\SelectionStrategy;
use BAGArt\ProxyOperations\Domain\Pool\PoolCandidateView;
use BAGArt\ProxyOperations\Domain\Pool\SelectionReasonCode as PoolReasonCode;
use BAGArt\ProxyOperations\Models\AuditTrigger;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Models\ProxyPoolDecision;
use BAGArt\ProxyOperations\Models\ProxyPoolMember;
use BAGArt\ProxyOperations\Models\ProxyLease;
use Illuminate\Support\Str;
use Throwable;

/**
 * The selection engine (plan §11.25):
 * criteria → candidate query (pool members) → hard filters → freshness gate
 * (stale → lazy-check job + skip) → eligibility → strategy order → leases.
 * No network checks happen here except the permitted out-of-process
 * lazy-check request (light audit job via JobStarter). Every candidate gets
 * a decision-log entry — "why skipped" is answerable without re-running the
 * selector. The selector never blocks and never partially fails: fewer
 * eligible candidates simply yield fewer leases.
 */
final class ProxySelector
{
    public function __construct(
        private readonly SelectionStrategy $strategy,
        private readonly LeaseService $leases,
        private readonly VerifiedEligibilityPolicy $eligibility,
        private readonly JobStarter $jobStarter,
        private readonly string $lazyCheckProbeProfile = 'light',
    ) {
    }

    /**
     * @return list<ProxyLeaseDto>
     */
    public function acquire(string $tenantId, SelectionCriteria $criteria): array
    {
        $runId = 'sel-'.Str::ulid()->toBase32();
        $policyVersion = 1;
        $decisions = [];
        $leases = [];

        $pool = $this->resolvePool($criteria->poolId);

        if ($pool === null) {
            // No pool to log against; the empty answer itself is the signal.
            return [];
        }

        $members = ProxyPoolMember::query()
            ->where('pool_id', $pool->id)
            ->with('access.endpoint')
            ->get()
            ->keyBy('access_id');

        $healths = ProxyHealth::query()->get()->keyBy('access_id');

        // Historical lease count per access — the least-used signal.
        $leaseCounts = ProxyLease::query()
            ->selectRaw('access_id, count(*) as leases')
            ->groupBy('access_id')
            ->pluck('leases', 'access_id');

        $scored = [];

        foreach ($members as $member) {
            $access = $member->access;

            if ($access === null) {
                continue;
            }

            $view = PoolCandidateView::fromModels($access, $healths->get($access->id));
            $score = $healths->get($access->id)?->health_score;

            // Hard filters first (cheapest, most specific).
            if (in_array($access->id, $criteria->excludeAccessIds, true)) {
                $decisions[] = new SelectionDecision($access->id, 'skipped', PoolReasonCode::Excluded, $score, $policyVersion);

                continue;
            }

            if ($criteria->requiredProtocol !== null && $view->protocol->value !== $criteria->requiredProtocol) {
                $decisions[] = new SelectionDecision($access->id, 'skipped', PoolReasonCode::PredicateProtocolMismatch, $score, $policyVersion);

                continue;
            }

            if ($criteria->telegramUsableOnly === true && $view->telegramUsableNow !== true) {
                $decisions[] = new SelectionDecision($access->id, 'skipped', PoolReasonCode::PredicateTelegramFilter, $score, $policyVersion);

                continue;
            }

            // Freshness gate (§11.25): stale candidates trigger a light
            // audit job and are skipped this round — the selector never
            // probes inline.
            $staleness = $this->stalenessSeconds($access, $healths->get($access->id));

            if ($criteria->maxStalenessSeconds !== null && $staleness !== null && $staleness > $criteria->maxStalenessSeconds) {
                $this->requestLazyCheck($access);
                $decisions[] = new SelectionDecision($access->id, 'skipped', PoolReasonCode::StaleHealth, $score, $policyVersion);

                continue;
            }

            if (! $this->isEligible($access, $healths->get($access->id))) {
                $decisions[] = new SelectionDecision($access->id, 'skipped', PoolReasonCode::NotEligible, $score, $policyVersion);

                continue;
            }

            $scored[] = new ScoredCandidate($access->id, $score, (int) ($leaseCounts[$access->id] ?? 0));
        }

        $remaining = $criteria->count;

        foreach ($this->strategy->order($scored) as $candidate) {
            if ($remaining === 0) {
                // Ordered beyond the requested count — not reached this run.
                $decisions[] = new SelectionDecision($candidate->accessId, 'skipped', PoolReasonCode::PoolEmpty, $candidate->score, $policyVersion);

                continue;
            }

            $access = ProxyAccess::query()->find($candidate->accessId);
            $lease = $access === null ? null : $this->leases->acquire($access, $criteria->holder, $criteria->purpose);

            if ($lease === null) {
                $decisions[] = new SelectionDecision($candidate->accessId, 'skipped', PoolReasonCode::LeaseUnavailable, $candidate->score, $policyVersion);

                continue;
            }

            $remaining--;
            $decisions[] = new SelectionDecision($candidate->accessId, 'selected', PoolReasonCode::Accepted, $candidate->score, $policyVersion);
            $leases[] = $lease;
        }

        $this->logDecisions($pool, $runId, $decisions);

        return $leases;
    }

    private function resolvePool(?string $poolId): ?ProxyPool
    {
        $query = ProxyPool::query()->where('enabled', true);

        return $poolId === null ? $query->orderBy('created_at')->first() : $query->find($poolId);
    }

    private function stalenessSeconds(ProxyAccess $access, ?ProxyHealth $health): ?int
    {
        $checked = $health?->computed_at ?? $access->last_checked_at;

        return $checked === null ? null : (int) $checked->diffInSeconds(now());
    }

    private function isEligible(ProxyAccess $access, ?ProxyHealth $health): bool
    {
        $satisfied = [];

        foreach ($health?->dimension_signals ?? [] as $dimension => $signal) {
            $type = EvidenceType::tryFrom((string) $dimension);

            if ($type !== null && $signal === 'pass') {
                $satisfied[] = $type;
            }
        }

        $endpoint = $access->endpoint;

        return $this->eligibility->isEligible(new VerifiedEligibilityCheck(
            protocol: $endpoint === null ? $access->endpoint()->firstOrFail()->protocol : $endpoint->protocol,
            satisfiedDimensions: $satisfied,
            lastTelegramCheckUsable: $access->telegram_usable === true,
            telegramCheckedAt: $access->telegram_checked_at?->toImmutable(),
            now: now()->toImmutable(),
        ));
    }

    private function requestLazyCheck(ProxyAccess $access): ?ProxyAuditJob
    {
        try {
            return $this->jobStarter->start(new AuditRequest(
                trigger: AuditTrigger::LazySelection,
                probeProfile: $this->lazyCheckProbeProfile,
                accessIds: [$access->id],
                requestedBy: null,
            ));
        } catch (Throwable) {
            // Lazy check is best-effort; skipping the candidate stands.
            return null;
        }
    }

    /**
     * @param  list<SelectionDecision>  $decisions
     */
    private function logDecisions(ProxyPool $pool, string $runId, array $decisions): void
    {
        $rows = [];

        foreach ($decisions as $decision) {
            $rows[] = [
                'id' => self::uuid(),
                'tenant_id' => $pool->tenant_id,
                'pool_id' => $pool->id,
                'materialization_id' => $runId,
                'access_id' => $decision->accessId,
                'decision' => $decision->decision,
                'reason_code' => $decision->reasonCode->value,
                'score' => $decision->score,
                'policy_version' => $decision->policyVersion,
                'created_at' => now()->toDateTimeString(),
            ];
        }

        if ($rows !== []) {
            ProxyPoolDecision::query()->insert($rows);
        }
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
