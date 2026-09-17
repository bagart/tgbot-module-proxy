<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Models\ProxyPolicy;

/**
 * Update settings handler.
 */
final class UpdateSettingsHandler
{
    private const ALLOWED_FIELDS = [
        'max_endpoints',
        'jobs_per_day',
        'concurrent_jobs',
        'max_import_file_bytes',
    ];

    public function handle(UpdateSettingsCommand $command): CommandResult
    {
        if (! in_array($command->field, self::ALLOWED_FIELDS, true)) {
            return CommandResult::fail('proxy.settings.invalid_field');
        }

        $policy = ProxyPolicy::firstOrCreate(
            ['tenant_id' => $command->tenantId],
            ProxyPolicy::defaultsForTenant(),
        );

        $quotas = $policy->quotas ?? [];
        $quotas[$command->field] = $command->value;
        $policy->update(['quotas' => $quotas]);

        return CommandResult::ok(
            messageKey: 'proxy.settings.updated',
            data: [$command->field => $command->value],
        );
    }
}
