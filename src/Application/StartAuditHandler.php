<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Audit\AuditRequest;
use BAGArt\ProxyOperations\Audit\JobStarter;
use BAGArt\ProxyOperations\Models\AuditTrigger;

/**
 * Start audit handler (plan §11.10, §11.18, §11.27).
 */
final class StartAuditHandler
{
    public function __construct(
        private JobStarter $jobStarter,
        private QuotaEnforcer $quotaEnforcer,
    ) {
    }

    public function handle(StartAuditCommand $command): CommandResult
    {
        $this->quotaEnforcer->enforceConcurrentAuditQuota($command->tenantId);

        $trigger = AuditTrigger::tryFrom($command->trigger) ?? AuditTrigger::Manual;

        $request = new AuditRequest(
            trigger: $trigger,
            probeProfile: 'standard',
            accessIds: $command->targetAccessIds,
            requestedBy: null,
        );

        $job = $this->jobStarter->start($request);

        return CommandResult::ok(
            messageKey: 'proxy.audit.started',
            data: ['job_id' => $job->id],
        );
    }
}
