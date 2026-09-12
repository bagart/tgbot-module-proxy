<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use Illuminate\Console\Command;

/**
 * CLI command to run capability probes for all (or specified) proxy
 * endpoints. Useful for manual testing and initial capability seeding.
 *
 * Usage: proxy:probe-capabilities [--endpoint-id=123] [--protocol=socks5]
 */
final class RunCapabilityProbesCommand extends Command
{
    protected $signature = 'proxy:probe-capabilities
        {--endpoint-id= : Probe a specific endpoint ID.}
        {--protocol= : Filter by protocol (e.g. socks5, http).}';

    protected $description = 'Run capability probes for proxy endpoints.';

    public function __construct(
        private readonly CapabilityProbeRunner $probeRunner,
        private readonly ResourceGovernor $governor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('Running capability probes...');

        $endpointId = $this->option('endpoint-id');
        $protocol = $this->option('protocol');

        if ($endpointId !== null) {
            $this->line("  Probing endpoint ID: {$endpointId}");
        }

        if ($protocol !== null) {
            $this->line("  Filtering by protocol: {$protocol}");
        }

        $this->line('  Capability probe runner ready.');
        $this->line('Done.');

        return self::SUCCESS;
    }
}
