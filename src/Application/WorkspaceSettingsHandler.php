<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Models\ProxyPolicy;

/**
 * Workspace settings handler.
 */
final class WorkspaceSettingsHandler
{
    public function handle(WorkspaceSettingsQuery $query): QueryResult
    {
        $policy = ProxyPolicy::where('tenant_id', $query->tenantId)->first();

        if ($policy === null) {
            return QueryResult::found(self::defaults());
        }

        $quotas = $policy->quotas ?? [];

        return QueryResult::found([
            'max_endpoints' => $quotas['max_endpoints'] ?? 1000,
            'jobs_per_day' => $quotas['jobs_per_day'] ?? 500,
            'concurrent_jobs' => $quotas['concurrent_jobs'] ?? 2,
            'max_import_file_bytes' => $quotas['max_import_file_bytes'] ?? 10_485_760,
        ]);
    }

    private static function defaults(): array
    {
        return ProxyPolicy::defaultsForTenant()['quotas'] ?? ProxyPolicy::QUOTA_FALLBACKS;
    }
}
