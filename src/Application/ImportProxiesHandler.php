<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Domain\Parsing\ImportProxiesCommand as DomainImportCommand;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;

/**
 * Import proxies handler.
 */
final class ImportProxiesHandler
{
    public function __construct(
        private ImportProxiesService $importService,
        private QuotaEnforcer $quotaEnforcer,
    ) {}

    public function handle(ImportProxiesCommand $command): CommandResult
    {
        $this->quotaEnforcer->enforceImportQuota($command->tenantId, substr_count($command->payload, "\n") + 1);

        $domainCommand = new DomainImportCommand(
            text: $command->payload,
            sourceLabel: $command->sourceLabel,
            tenantId: (int) $command->tenantId,
            idempotencyKey: null,
        );

        $result = $this->importService->execute($domainCommand);

        return CommandResult::ok(
            messageKey: 'proxy.import.success',
            data: [
                'imported' => $result->created,
                'skipped' => $result->skipped,
                'errors' => $result->errors,
            ],
        );
    }
}
