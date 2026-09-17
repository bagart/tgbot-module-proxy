<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

use Illuminate\Console\Command;

/**
 * CLI command: proxy:inventory:export
 * Exports proxy inventory to a file (plan §11.28, T44).
 */
class ProxyInventoryExportCommand extends Command
{
    protected $signature = 'proxy:inventory:export
        {--format=json : Export format (json|csv|txt|txt_scheme|txt_full|proxychains|curl|clash|tg)}
        {--pool= : Pool ID to export (optional)}
        {--credentials : Include plaintext credentials (requires confirmation)}
        {--output= : Output file path (default: stdout)}
        {--tg-ready : Export only Telegram-ready proxies}';

    protected $description = 'Export proxy inventory to file or stdout';

    public function handle(ExportService $exportService, ExportAuditLogger $auditLogger): int
    {
        $format = $this->option('format');

        if (! $this->confirmCredentialsOption()) {
            return self::FAILURE;
        }

        $query = new ExportQuery(
            tenantId: $this->resolveTenantId(),
            format: $format,
            includeCredentials: $this->option('credentials'),
            poolId: $this->option('pool'),
            tgReadyOnly: $this->option('tg-ready'),
        );

        $result = $exportService->export($query);

        $auditLogger->log(
            tenantId: $query->tenantId,
            exportId: $result->exportId,
            format: $format,
            recordCount: $result->recordCount,
            includeCredentials: $query->includeCredentials,
            requestedBy: 'cli',
        );

        $output = $this->option('output');

        if ($output !== null && $output !== '') {
            file_put_contents($output, $result->content);
            $this->info("Exported {$result->recordCount} records to {$output}");
        } else {
            $this->line($result->content);
        }

        $this->info("Format: {$format} | Records: {$result->recordCount} | File: {$result->filename}");

        return self::SUCCESS;
    }

    private function confirmCredentialsOption(): bool
    {
        if ($this->option('credentials')) {
            return $this->confirm(
                'You are about to export plaintext credentials. Are you sure?',
                false,
            );
        }

        return true;
    }

    private function resolveTenantId(): string
    {
        // In CLI context, resolve from current user or default tenant
        $user = auth()->user();

        return $user ? (string) $user->id : '1';
    }
}
