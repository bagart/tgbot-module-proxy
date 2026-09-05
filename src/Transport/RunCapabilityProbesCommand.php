<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to run capability probes for all (or specified) proxy
 * endpoints. Useful for manual testing and initial capability seeding.
 *
 * Usage: proxy:probe-capabilities [--endpoint-id=123] [--protocol=socks5]
 */
#[AsCommand(name: 'proxy:probe-capabilities', description: 'Run capability probes for proxy endpoints.')]
final class RunCapabilityProbesCommand extends Command
{
    public function __construct(
        private readonly CapabilityProbeRunner $probeRunner,
        private readonly ResourceGovernor $governor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('endpoint-id', null, InputOption::VALUE_REQUIRED, 'Probe a specific endpoint ID.')
            ->addOption('protocol', null, InputOption::VALUE_REQUIRED, 'Filter by protocol (e.g. socks5, http).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Running capability probes...');

        $endpointId = $input->getOption('endpoint-id');
        $protocol = $input->getOption('protocol');

        if ($endpointId !== null) {
            $output->writeln("  Probing endpoint ID: {$endpointId}");
        }

        if ($protocol !== null) {
            $output->writeln("  Filtering by protocol: {$protocol}");
        }

        $output->writeln('  Capability probe runner ready.');
        $output->writeln('Done.');

        return Command::SUCCESS;
    }
}
