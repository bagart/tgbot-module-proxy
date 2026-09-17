<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Models\ProxyAuditJob;

/**
 * Audit status handler.
 */
final class AuditStatusHandler
{
    public function handle(AuditStatusQuery $query): QueryResult
    {
        $job = ProxyAuditJob::where('tenant_id', $query->tenantId)
            ->where('id', $query->jobId)
            ->first();

        if ($job === null) {
            return QueryResult::notFound('proxy.audit.not_found');
        }

        return QueryResult::found([
            'id' => $job->id,
            'status' => $job->status->value,
            'trigger' => $job->trigger->value,
            'progress' => $job->progress,
            'created_at' => $job->created_at,
        ]);
    }
}
