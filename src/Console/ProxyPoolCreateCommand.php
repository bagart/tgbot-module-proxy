<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Audit\PoolRepository;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Create a proxy pool (T62, plan §§11.10, #47).
 */
class ProxyPoolCreateCommand extends Command
{
    protected $signature = 'proxy:pools:create
        {name : Pool name}
        {--kind=dynamic : Pool kind (dynamic|static)}
        {--predicate= : JSON predicate for dynamic pools}';

    protected $description = 'Create a proxy pool';

    public function __construct(
        private PoolRepository $pools,
        private TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = $this->argument('name');
        $kind = $this->option('kind');

        $attributes = [
            'name' => $name,
            'kind' => $kind,
            'enabled' => true,
        ];

        if ($this->option('predicate') !== null) {
            $decoded = json_decode($this->option('predicate'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON predicate.');

                return self::FAILURE;
            }
            $attributes['predicate'] = $decoded;
        }

        $pool = $this->pools->create($attributes);

        $this->info("Pool created: {$pool->name} ({$pool->id})");

        return self::SUCCESS;
    }
}
