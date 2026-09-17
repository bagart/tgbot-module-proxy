<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Audit\PoolRepository;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * List proxy pools (T62, plan §§11.10, #47).
 */
class ProxyPoolListCommand extends Command
{
    protected $signature = 'proxy:pools:list
        {--json : Output as JSON}';

    protected $description = 'List proxy pools with member counts';

    public function __construct(
        private PoolRepository $pools,
        private TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pools = $this->pools->enabledPools();

        if ($pools->isEmpty()) {
            $this->info('No pools found.');

            return self::SUCCESS;
        }

        $rows = $pools->map(fn (ProxyPool $p) => [
            $p->id,
            $p->name,
            $p->kind?->value ?? 'dynamic',
            $p->enabled ? 'yes' : 'no',
            $p->members()->count(),
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($rows->toArray(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['ID', 'Name', 'Kind', 'Enabled', 'Members'], $rows);

        return self::SUCCESS;
    }
}
