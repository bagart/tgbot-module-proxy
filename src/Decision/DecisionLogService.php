<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Decision;

use BAGArt\ProxyOperations\Models\Decision;
use BAGArt\ProxyOperations\Models\DecisionType;
use Illuminate\Support\Facades\Log;

/**
 * Decision log service (plan §§10.12 п.13, 11.10).
 * Records and queries all significant system decisions.
 */
final class DecisionLogService
{
    /**
     * Record a decision.
     */
    public function record(
        string $tenantId,
        DecisionType $type,
        string $entityType,
        ?int $entityId,
        string $action,
        string $reason = '',
        array $metadata = [],
        ?int $userId = null,
    ): Decision {
        $decision = Decision::create([
            'tenant_id' => $tenantId,
            'decision_type' => $type,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'reason' => $reason,
            'metadata' => $metadata,
            'user_id' => $userId,
        ]);

        Log::info('proxy.decision.recorded', [
            'decision_id' => $decision->id,
            'type' => $type->value,
            'entity' => $entityType . '#' . $entityId,
            'action' => $action,
        ]);

        return $decision;
    }

    /**
     * Query decisions with filters.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function query(
        string $tenantId,
        ?DecisionType $type = null,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $from = null,
        ?string $to = null,
        int $perPage = 50,
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator {
        $query = Decision::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($type !== null) {
            $query->where('decision_type', $type->value);
        }

        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }

        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query->paginate($perPage);
    }

    /**
     * Get timeline for an entity.
     *
     * @return list<Decision>
     */
    public function timeline(string $tenantId, string $entityType, int $entityId): array
    {
        return Decision::query()
            ->where('tenant_id', $tenantId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }
}
