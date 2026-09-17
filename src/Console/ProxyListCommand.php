<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * List proxies in the inventory (T62, plan §§11.10, #47).
 */
class ProxyListCommand extends Command
{
    protected $signature = 'proxy:list
        {--protocol= : Filter by protocol}
        {--state= : Filter by state (new|healthy|degraded|failing|dead)}
        {--limit=50 : Maximum rows to display}
        {--json : Output as JSON}';

    protected $description = 'List proxies in the inventory';

    public function __construct(private TenantContext $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (string) ($this->tenant->tryId() ?? '1');

        $query = ProxyAccess::query()
            ->where('tenant_id', $tenantId)
            ->with('endpoint');

        if ($this->option('protocol') !== null) {
            $query->whereHas('endpoint', fn ($q) => $q->where('protocol', $this->option('protocol')));
        }

        if ($this->option('state') !== null) {
            $query->where('state', $this->option('state'));
        }

        $rows = $query->limit((int) $this->option('limit'))->get();

        if ($this->option('json')) {
            $this->line(json_encode($rows->toArray(), JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($rows->isEmpty()) {
            $this->info('No proxies found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Protocol', 'Host', 'Port', 'State'],
            $rows->map(fn (ProxyAccess $a) => [
                $a->id,
                $a->endpoint?->protocol?->value ?? '?',
                $a->endpoint?->host ?? '?',
                $a->endpoint?->port ?? '?',
                $a->state->value ?? 'unknown',
            ]),
        );

        return self::SUCCESS;
    }
}
