<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Console;

use BAGArt\ProxyOperations\Benchmark\BenchmarkRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Run SLO benchmark harness (plan §11.10 п.5).
 * Executes proxy checks across format/concurrency matrix and produces report.
 */
class ProxyBenchmarkCommand extends Command
{
    protected $signature = 'proxy:benchmark
                            {--formats=socks5,http : Comma-separated proxy formats}
                            {--concurrencies=1,5 : Concurrency levels}
                            {--samples=50 : Samples per format}
                            {--output= : Output file path for JSON report}
                            {--quiet : Suppress table output}';

    protected $description = 'Run SLO benchmark harness against proxy endpoints';

    public function __construct(
        private readonly BenchmarkRunner $runner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $formats = array_map('trim', explode(',', $this->option('formats')));
        $concurrencies = array_map('intval', explode(',', $this->option('concurrencies')));
        $samples = (int) $this->option('samples');

        $this->info("Running SLO benchmark...");
        $this->line("  Formats: " . implode(', ', $formats));
        $this->line("  Concurrency: " . implode(', ', $concurrencies));
        $this->line("  Samples per format: {$samples}");
        $this->newLine();

        $report = $this->runner->run(
            formats: $formats,
            concurrencies: $concurrencies,
            samplesPerFormat: $samples,
        );

        if (! $this->option('quiet')) {
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total checks', $report->totalChecks],
                    ['Successes', $report->successes],
                    ['Failures', $report->failures],
                    ['Success rate', $report->successRate . '%'],
                    ['P50 latency', $report->p50LatencyMs . ' ms'],
                    ['P95 latency', $report->p95LatencyMs . ' ms'],
                    ['P99 latency', $report->p99LatencyMs . ' ms'],
                    ['Avg latency', $report->avgLatencyMs . ' ms'],
                    ['Duration', $report->durationMs . ' ms'],
                ],
            );

            if ($report->errorBreakdown !== []) {
                $this->newLine();
                $this->info('Error breakdown:');
                foreach ($report->errorBreakdown as $error => $count) {
                    $this->line("  {$error}: {$count}");
                }
            }
        }

        $outputPath = $this->option('output');
        if ($outputPath !== null) {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            File::put($outputPath, json_encode($report->jsonSerialize(), JSON_PRETTY_PRINT));
            $this->info("Report saved to: {$outputPath}");
        }

        $sloPass = $report->successRate >= 90 && $report->p95LatencyMs <= 5000;

        if ($sloPass) {
            $this->info('SLO: PASS (≥90% success, ≤5s P95)');
        } else {
            $this->warn('SLO: FAIL');
        }

        return $sloPass ? self::SUCCESS : self::FAILURE;
    }
}
