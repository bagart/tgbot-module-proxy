<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Configure and manage WAL archiving for PITR (plan §11.10 п.3).
 * Generates postgresql.conf snippets and manages archive lifecycle.
 */
class ProxyWalArchiveCommand extends Command
{
    protected $signature = 'proxy:wal:archive
                            {--action=generate : generate|status|cleanup}
                            {--archive-path=/var/lib/postgresql/wal_archive : Archive directory}
                            {--retention-days=7 : Days to keep archived WALs}
                            {--max-size-gb=10 : Max archive size in GB}';

    protected $description = 'Manage WAL archiving for proxy module PITR';

    public function handle(): int
    {
        return match ($this->option('action')) {
            'generate' => $this->generateConfig(),
            'status' => $this->showStatus(),
            'cleanup' => $this->cleanup(),
            default => $this->generateConfig(),
        };
    }

    private function generateConfig(): int
    {
        $archivePath = $this->option('archive-path');
        $config = $this->buildPostgresConfig($archivePath);

        $outputPath = storage_path('app/proxy_pitrr/postgresql-proxy.conf');

        if (! is_dir(dirname($outputPath))) {
            File::makeDirectory(dirname($outputPath), 0755, true);
        }

        File::put($outputPath, $config);

        $this->info("Generated WAL archive config: {$outputPath}");
        $this->newLine();
        $this->info('Add to your PostgreSQL config:');
        $this->line("  include '#{$outputPath}'");
        $this->newLine();
        $this->info('Ensure archive_command is set:');
        $this->line("  archive_command = 'test ! -f {$archivePath}/%f && cp %p {$archivePath}/%f'");
        $this->line('  archive_mode = on');
        $this->line('  wal_level = replica');

        return self::SUCCESS;
    }

    private function buildPostgresConfig(string $archivePath): string
    {
        $now = date('Y-m-d H:i:s');

        return <<<SQL
-- Proxy module PITR configuration
-- Generated: {$now}

-- WAL archiving
archive_mode = on
archive_command = 'test ! -f {$archivePath}/%f && cp %p {$archivePath}/%f'
archive_timeout = 300

-- WAL retention
max_wal_size = 2GB
min_wal_size = 1GB
wal_keep_size = 512MB

-- Replication (for read replicas)
max_replication_slots = 4
max_wal_senders = 4
SQL;
    }

    private function showStatus(): int
    {
        $archivePath = $this->option('archive-path');

        if (! is_dir($archivePath)) {
            $this->warn("Archive directory does not exist: {$archivePath}");

            return self::SUCCESS;
        }

        $files = File::files($archivePath);
        $totalSize = array_sum(array_map(fn ($f) => $f->getSize(), $files));

        $this->table(
            ['Metric', 'Value'],
            [
                ['Archive path', $archivePath],
                ['WAL files', count($files)],
                ['Total size', round($totalSize / 1024 / 1024, 2) . ' MB'],
                ['Oldest file', $files !== [] ? date('Y-m-d H:i:s', $files[0]->getMTime()) : 'N/A'],
                ['Newest file', $files !== [] ? date('Y-m-d H:i:s', end($files)->getMTime()) : 'N/A'],
            ],
        );

        return self::SUCCESS;
    }

    private function cleanup(): int
    {
        $archivePath = $this->option('archive-path');
        $retentionDays = (int) $this->option('retention-days');
        $maxSizeGb = (int) $this->option('max-size-gb');

        if (! is_dir($archivePath)) {
            $this->warn("Archive directory does not exist: {$archivePath}");

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($retentionDays);
        $deleted = 0;
        $totalSize = 0;

        $files = collect(File::files($archivePath))
            ->sortBy('getMTime')
            ->values();

        foreach ($files as $file) {
            if ($file->getMTime() < $cutoff->timestamp || $totalSize > $maxSizeGb * 1024 * 1024 * 1024) {
                File::delete($file->getRealPath());
                $deleted++;
            } else {
                $totalSize += $file->getSize();
            }
        }

        $this->info("Cleanup complete: deleted {$deleted} WAL files.");

        return self::SUCCESS;
    }
}
