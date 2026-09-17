<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportProxiesHandler;
use BAGArt\ProxyOperations\Application\ImportSource;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Import proxies from file or stdin (T62, plan §§11.10, #47).
 */
class ProxyImportCommand extends Command
{
    protected $signature = 'proxy:import
        {file? : File to import (optional, reads stdin if omitted)}
        {--source=paste : Source label (paste|file|feed)}
        {--label= : Optional source label for audit trail}';

    protected $description = 'Import proxies from a file or stdin';

    public function __construct(
        private ImportProxiesHandler $handler,
        private TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $file = $this->argument('file');

        if ($file !== null) {
            if (! is_file($file)) {
                $this->error("File not found: {$file}");

                return self::FAILURE;
            }
            $payload = file_get_contents($file);
            $source = ImportSource::File;
        } else {
            $payload = file_get_contents('php://stdin');
            $source = ImportSource::Paste;
        }

        if ($payload === false || $payload === '') {
            $this->error('No input provided.');

            return self::FAILURE;
        }

        $tenantId = (string) ($this->tenant->tryId() ?? '1');

        $command = new ImportProxiesCommand(
            tenantId: $tenantId,
            source: $source,
            payload: $payload,
            sourceLabel: $this->option('label'),
        );

        $result = $this->handler->handle($command);

        if (! $result->success) {
            $this->error("Import failed: {$result->errorKey}");

            return self::FAILURE;
        }

        $data = $result->data ?? [];
        $imported = $data['imported'] ?? 0;
        $skipped = $data['skipped'] ?? 0;
        $errors = is_array($data['errors'] ?? null) ? count($data['errors']) : ($data['errors'] ?? 0);

        $this->info("Imported: {$imported} | Skipped: {$skipped} | Errors: {$errors}");

        return self::SUCCESS;
    }
}
