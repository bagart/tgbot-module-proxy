<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

use BAGArt\ProxyOperations\Export\ExportAuditLogger;
use BAGArt\ProxyOperations\Export\ExportQuery;
use BAGArt\ProxyOperations\Export\ExportService;

/**
 * Export proxies handler.
 */
final class ExportInventoryHandler
{
    public function __construct(
        private ExportService $exportService,
        private ExportAuditLogger $auditLogger,
    ) {
    }

    public function handle(ExportInventoryCommand $command): CommandResult
    {
        $query = new ExportQuery(
            tenantId: $command->tenantId,
            format: $command->format,
            txtVariant: $command->txtVariant,
            includeCredentials: $command->includeCredentials,
            poolId: $command->poolId,
            tgReadyOnly: $command->tgReadyOnly,
        );

        $result = $this->exportService->export($query);

        $this->auditLogger->log(
            tenantId: $command->tenantId,
            exportId: $result->exportId,
            format: $command->format,
            recordCount: $result->recordCount,
            includeCredentials: $command->includeCredentials,
            requestedBy: $command->requestedBy,
        );

        return CommandResult::ok(
            messageKey: 'proxy.export.success',
            data: [
                'export_id' => $result->exportId,
                'filename' => $result->filename,
                'record_count' => $result->recordCount,
                'content' => $result->content,
                'mime_type' => $result->mimeType,
            ],
        );
    }
}
