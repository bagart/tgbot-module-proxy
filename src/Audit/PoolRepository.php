<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Models\ProxyPoolMember;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Application-layer wrapper for pool definitions and their membership
 * projection (plan §§11.21, 11.25, 11.22): every query is tenant-scoped
 * through the BelongsToTenant global scope, and cross-tenant attachment is
 * rejected before it can violate the projection's integrity.
 */
final class PoolRepository
{
    public function __construct(private readonly TenantContext $tenant)
    {
    }

    /**
     * @param  array<string, mixed>  $attributes  Pool columns except tenant_id.
     */
    public function create(array $attributes): ProxyPool
    {
        return ProxyPool::query()->create($attributes);
    }

    public function findForTenant(string $poolId): ?ProxyPool
    {
        return ProxyPool::query()->find($poolId);
    }

    /**
     * @return Collection<int, ProxyPoolMember>
     */
    public function membersOf(ProxyPool $pool): Collection
    {
        return $pool->members()->get();
    }

    public function attachMember(ProxyPool $pool, string $accessId, ?int $materializationVersion = null): ProxyPoolMember
    {
        $access = ProxyAccess::query()->find($accessId);

        if ($access === null) {
            // Either nonexistent or another tenant's — never leak which.
            throw new InvalidArgumentException('Access does not exist in the current workspace.');
        }

        $member = new ProxyPoolMember([
            'pool_id' => $pool->id,
            'access_id' => $access->id,
            'added_at' => now(),
            'materialization_version' => $materializationVersion,
        ]);
        $member->save();

        return $member;
    }

    public function detachMember(ProxyPool $pool, string $accessId): void
    {
        $pool->members()->where('access_id', $accessId)->delete();
    }

    /**
     * Enabled pools of the current tenant.
     *
     * @return Collection<int, ProxyPool>
     */
    public function enabledPools(): Collection
    {
        return ProxyPool::query()->where('enabled', true)->orderBy('name')->get();
    }

    /**
     * Members joined with their access rows for predicate evaluation.
     *
     * @return Collection<int, ProxyPoolMember>
     */
    public function membersWithAccess(ProxyPool $pool): Collection
    {
        return $pool->members()->with('access.endpoint')->get();
    }

    /**
     * Pool lookup bypassing the tenant scope — for system-level jobs only
     * (reaper-style scans); the caller MUST verify tenancy itself.
     */
    public function findAnyTenant(string $poolId): ?ProxyPool
    {
        /** @var Builder<ProxyPool> $query */
        $query = ProxyPool::query();

        return $query->withoutGlobalScopes()->firstWhere('id', $poolId);
    }
}
