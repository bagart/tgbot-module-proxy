<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyPolicy;
use RuntimeException;

/**
 * Enforces workspace quotas from ProxyPolicy (plan §11.29).
 */
final class QuotaEnforcer
{
    public function enforceImportQuota(string $tenantId, int $batchSize): void
    {
        $policy = ProxyPolicy::where('tenant_id', $tenantId)->first();

        if ($policy === null) {
            return;
        }

        $maxBatch = $policy->getAttribute('max_import_batch') ?? 10000;

        if ($batchSize > $maxBatch) {
            throw new RuntimeException("Import batch size {$batchSize} exceeds quota {$maxBatch}");
        }
    }

    public function enforceConcurrentAuditQuota(string $tenantId): void
    {
        $policy = ProxyPolicy::where('tenant_id', $tenantId)->first();

        if ($policy === null) {
            return;
        }

        $maxConcurrent = $policy->getAttribute('max_concurrent_audits') ?? 5;
        $running = ProxyAuditJob::where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'running'])
            ->count();

        if ($running >= $maxConcurrent) {
            throw new RuntimeException("Concurrent audit quota exceeded: {$running}/{$maxConcurrent}");
        }
    }
}
