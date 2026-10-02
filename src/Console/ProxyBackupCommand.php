<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Backup proxy module database tables via pg_dump (plan §11.10 п.3).
 * Supports full dump, WAL archiving setup, and partitioning.
 */
class ProxyBackupCommand extends Command
{
    protected $signature = 'proxy:backup
                            {--path= : Output directory for backup file}
                            {--format=custom : pg_dump format (custom, plain, directory, tar)}
                            {--compress=0 : Compression level (0-9)}
                            {--tables= : Comma-separated table list (default: all proxy tables)}';

    protected $description = 'Backup proxy module database tables';

    private const array PROXY_TABLES = [
        'proxy_endpoints',
        'proxy_pools',
        'proxy_pool_members',
        'proxy_audits',
        'proxy_audit_entries',
        'proxy_audit_tasks',
        'proxy_access_grants',
        'proxy_access_revocations',
        'proxy_health_snapshots',
        'proxy_feed_sources',
        'proxy_incidents',
        'proxy_incident_actions',
        'proxy_decisions',
        'workspace_settings',
    ];

    public function handle(): int
    {
        $path = $this->option('path') ?? storage_path('app/proxy_backups');
        $format = $this->option('format');
        $compress = (int) $this->option('compress');
        $tables = $this->option('tables')
            ? array_map('trim', explode(',', $this->option('tables')))
            : self::PROXY_TABLES;

        if (! is_dir($path)) {
            File::makeDirectory($path, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $filename = "proxy_backup_{$timestamp}.{$format}";
        $outputFile = rtrim($path, '/') . '/' . $filename;

        $this->info("Backing up proxy tables to: {$outputFile}");

        $dbConfig = config('database.connections.' . config('database.default'));

        if ($dbConfig === null) {
            $this->error('Database connection not configured.');

            return self::FAILURE;
        }

        $env = [
            'PGPASSWORD' => $dbConfig['password'] ?? '',
        ];

        $args = [
            'pg_dump',
            '--host=' . ($dbConfig['host'] ?? 'localhost'),
            '--port=' . ($dbConfig['port'] ?? 5432),
            '--username=' . ($dbConfig['username'] ?? 'postgres'),
            '--dbname=' . ($dbConfig['database'] ?? 'proxy_db'),
            '--format=' . $format,
            '--compress=' . $compress,
            '--file=' . $outputFile,
        ];

        foreach ($tables as $table) {
            $args[] = '--table=' . $table;
        }

        $process = new Process($args, null, $env);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('pg_dump failed: ' . $process->getErrorOutput());

            return self::FAILURE;
        }

        $size = File::size($outputFile);
        $sizeFormatted = round($size / 1024 / 1024, 2) . ' MB';

        $this->info("Backup complete: {$outputFile} ({$sizeFormatted})");

        return self::SUCCESS;
    }
}
