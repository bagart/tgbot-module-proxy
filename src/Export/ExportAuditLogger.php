<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Logs export actions to the proxy_exports table (plan §11.28).
 */
final class ExportAuditLogger
{
    public function log(
        string $tenantId,
        string $exportId,
        string $format,
        int $recordCount,
        bool $includeCredentials,
        ?string $requestedBy = null,
    ): void {
        DB::table('proxy_exports')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'export_id' => $exportId,
            'format' => $format,
            'record_count' => $recordCount,
            'include_credentials' => $includeCredentials,
            'requested_by' => $requestedBy,
            'created_at' => now(),
        ]);
    }
}
