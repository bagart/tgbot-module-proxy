<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Application\ExportInventoryCommand;
use BAGArt\ProxyOperations\Application\ExportInventoryHandler;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Export proxies (T62, plan §§11.10, #47).
 */
class ProxyExportCommand extends Command
{
    protected $signature = 'proxy:export
        {--format=json : Export format (json|csv|txt|txt_scheme|txt_full|proxychains|curl|clash|tg)}
        {--output= : Output file path (default: stdout)}
        {--pool= : Pool ID to export}
        {--credentials : Include plaintext credentials}
        {--tg-ready : Export only Telegram-ready proxies}';

    protected $description = 'Export proxies to file or stdout';

    public function __construct(
        private ExportInventoryHandler $handler,
        private TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (string) ($this->tenant->tryId() ?? '1');

        $command = new ExportInventoryCommand(
            tenantId: $tenantId,
            format: $this->option('format'),
            includeCredentials: $this->option('credentials'),
            poolId: $this->option('pool'),
            tgReadyOnly: $this->option('tg-ready'),
            requestedBy: 'cli',
        );

        $result = $this->handler->handle($command);

        if (! $result->success) {
            $this->error("Export failed: {$result->errorKey}");

            return self::FAILURE;
        }

        $data = $result->data ?? [];
        $content = $data['content'] ?? '';
        $recordCount = $data['record_count'] ?? 0;

        $output = $this->option('output');

        if ($output !== null && $output !== '') {
            file_put_contents($output, $content);
            $this->info("Exported {$recordCount} records to {$output}");
        } else {
            $this->line($content);
        }

        $this->info("Records: {$recordCount} | Format: {$this->option('format')}");

        return self::SUCCESS;
    }
}
