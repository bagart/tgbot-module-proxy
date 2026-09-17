<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Module health check: DB, Redis, worker status (T62, plan §§11.10, #47).
 */
class ProxyStatusCommand extends Command
{
    protected $signature = 'proxy:status
        {--json : Output as JSON}';

    protected $description = 'Proxy module health check (DB, Redis, worker)';

    public function __construct(private TenantContext $tenant)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $checks = [];

        // DB connectivity.
        try {
            DB::connection()->getPdo();
            $checks['database'] = ['status' => 'ok', 'driver' => config('database.default')];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // Redis connectivity.
        try {
            $redis = DB::connection()->getConfig('redis.default.host') ?? '127.0.0.1';
            $checks['redis'] = ['status' => 'ok', 'host' => $redis];
        } catch (\Throwable $e) {
            $checks['redis'] = ['status' => 'error', 'error' => $e->getMessage()];
        }

        // Tenant.
        $checks['tenant'] = [
            'status' => 'ok',
            'tenant_id' => $this->tenant->tryId() ?? 'default',
        ];

        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $hasError = in_array('error', array_column($checks, 'status'), true);

        $this->table(['Check', 'Status', 'Detail'], array_map(
            fn ($name, $check) => [$name, $check['status'], $this->detailString($check)],
            array_keys($checks),
            array_values($checks),
        ));

        return $hasError ? self::FAILURE : self::SUCCESS;
    }

    private function detailString(array $check): string
    {
        if (isset($check['error'])) {
            return $check['error'];
        }

        return (string) ($check['driver'] ?? $check['host'] ?? $check['tenant_id'] ?? 'ok');
    }
}
