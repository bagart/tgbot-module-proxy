<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Application\ApplicationServiceBus;
use BAGArt\ProxyOperations\Application\UpdateSettingsCommand;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsQuery;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Show or update proxy settings (T62, plan §§11.10, #47).
 */
class ProxySettingsCommand extends Command
{
    protected $signature = 'proxy:settings
        {--field= : Setting field to update}
        {--value= : New value (requires --field)}
        {--json : Output as JSON}';

    protected $description = 'Show or update proxy workspace settings';

    public function __construct(
        private ApplicationServiceBus $bus,
        private TenantContext $tenant,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantId = (string) ($this->tenant->tryId() ?? '1');
        $field = $this->option('field');
        $value = $this->option('value');

        if ($field !== null && $value !== null) {
            // Update mode.
            $typedValue = is_numeric($value) ? (int) $value : $value;

            $command = new UpdateSettingsCommand(
                tenantId: $tenantId,
                field: $field,
                value: $typedValue,
                updatedBy: 'cli',
            );

            $result = $this->bus->dispatch($command);

            if (! $result->success) {
                $this->error("Update failed: {$result->errorKey}");

                return self::FAILURE;
            }

            $this->info("Updated {$field} = {$value}");

            return self::SUCCESS;
        }

        // Read mode.
        $query = new WorkspaceSettingsQuery(tenantId: $tenantId);
        $result = $this->bus->query($query);

        if ($this->option('json')) {
            $this->line(json_encode($result->data ?? [], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (! $result->found) {
            $this->info('Using default settings.');

            return self::SUCCESS;
        }

        $this->table(
            ['Field', 'Value'],
            array_map(fn ($k, $v) => [$k, $v], array_keys($result->data), array_values($result->data)),
        );

        return self::SUCCESS;
    }
}
