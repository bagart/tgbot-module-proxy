<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Application\StartAuditCommand;
use BAGArt\ProxyOperations\Application\StartAuditHandler;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Run audit on proxies (T62, plan §§11.10, #47).
 */
class ProxyCheckCommand extends Command
{
    protected $signature = 'proxy:check
        {access_id? : Specific access ID to check (optional)}
        {--profile=standard : Audit profile (standard|deep|quick)}
        {--json : Output as JSON}';

    protected $description = 'Run audit on proxies';

    public function __construct(
        private StartAuditHandler $auditHandler,
        private TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (string) ($this->tenant->tryId() ?? '1');
        $accessId = $this->argument('access_id');

        $command = new StartAuditCommand(
            tenantId: $tenantId,
            trigger: 'cli',
            targetAccessIds: $accessId !== null ? [$accessId] : [],
            requestedBy: 'cli',
        );

        $result = $this->auditHandler->handle($command);

        if (! $result->success) {
            $this->error("Audit failed: {$result->errorKey}");

            return self::FAILURE;
        }

        $data = $result->data ?? [];

        if ($this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $jobId = $data['job_id'] ?? '?';
        $this->info("Audit started: job {$jobId}");

        return self::SUCCESS;
    }
}
