<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Models\ProxyAuditJob;

/**
 * Cancel audit handler.
 */
final class CancelAuditHandler
{
    public function handle(CancelAuditCommand $command): CommandResult
    {
        $job = ProxyAuditJob::where('tenant_id', $command->tenantId)
            ->where('id', $command->jobId)
            ->first();

        if ($job === null) {
            return CommandResult::fail('proxy.audit.not_found');
        }

        if ($job->status->value === 'completed' || $job->status->value === 'cancelled') {
            return CommandResult::fail('proxy.audit.already_terminal');
        }

        $job->update(['status' => 'cancelled']);

        return CommandResult::ok('proxy.audit.cancelled');
    }
}
