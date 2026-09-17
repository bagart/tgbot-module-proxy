<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Benchmark;

use BAGArt\ProxyOperations\Audit\LeaseService;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SLO benchmark runner (plan §11.10 п.5).
 * Executes matrix of format × concurrency and produces SloReport.
 * Uses direct HTTP/TCP probes instead of AuditExecutorContract.
 */
final class BenchmarkRunner
{
    private const int TIMEOUT_MS = 5000;

    public function __construct(
        private readonly LeaseService $leases,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Run SLO benchmark against a matrix of proxy formats and concurrency levels.
     *
     * @param  list<string>  $formats  ['socks5', 'http', 'https', 'mtproto']
     * @param  list<int>  $concurrencies  [1, 5, 10]
     */
    public function run(
        array $formats = ['socks5', 'http'],
        array $concurrencies = [1],
        int $samplesPerFormat = 50,
    ): SloReport {
        $startMs = microtime(true);
        $latencies = [];
        $errors = [];
        $formatsStats = [];
        $totalChecks = 0;
        $successes = 0;

        foreach ($formats as $format) {
            $endpoints = $this->getEndpointsForFormat($format, $samplesPerFormat);

            foreach ($concurrencies as $concurrency) {
                $chunks = array_chunk($endpoints, $concurrency);

                foreach ($chunks as $chunk) {
                    foreach ($chunk as $endpoint) {
                        $result = $this->checkEndpoint($endpoint);
                        $totalChecks++;

                        if ($result !== null) {
                            $latencies[] = $result['latency_ms'];
                            $formatsStats[$format] = ($formatsStats[$format] ?? 0) + 1;

                            if ($result['success']) {
                                $successes++;
                            } else {
                                $errors[$result['error'] ?? 'unknown'] = ($errors[$result['error'] ?? 'unknown'] ?? 0) + 1;
                            }
                        } else {
                            $errors['exception'] = ($errors['exception'] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        $durationMs = (int) ((microtime(true) - $startMs) * 1000);

        return SloReport::fromRaw(
            totalChecks: $totalChecks,
            successes: $successes,
            latencies: $latencies,
            errors: $errors,
            formats: $formatsStats,
            durationMs: $durationMs,
        );
    }

    private function getEndpointsForFormat(string $format, int $limit): array
    {
        return ProxyEndpoint::query()
            ->where('tenant_id', $this->tenant->current())
            ->where('format', $format)
            ->where('is_active', true)
            ->limit($limit)
            ->get()
            ->toArray();
    }

    private function checkEndpoint(array $endpoint): ?array
    {
        $startMs = microtime(true);

        try {
            $lease = $this->leases->acquire(
                tenantId: $endpoint['tenant_id'] ?? '',
                endpointId: $endpoint['id'] ?? 0,
                ttlSeconds: 30,
            );

            if ($lease === null) {
                return ['success' => false, 'latency_ms' => 0, 'error' => 'lease_unavailable'];
            }

            $host = $endpoint['host'] ?? '';
            $port = (int) ($endpoint['port'] ?? 0);

            $success = $this->probeEndpoint($host, $port, $endpoint['format'] ?? 'socks5');

            $this->leases->release($lease->accessId);

            $latencyMs = (int) ((microtime(true) - $startMs) * 1000);

            return [
                'success' => $success,
                'latency_ms' => $latencyMs,
                'error' => $success ? null : 'probe_failed',
            ];
        } catch (Throwable) {
            return ['success' => false, 'latency_ms' => 0, 'error' => 'exception'];
        }
    }

    private function probeEndpoint(string $host, int $port, string $format): bool
    {
        if ($host === '' || $port <= 0) {
            return false;
        }

        $errno = 0;
        $errstr = '';

        $fp = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT_MS / 1000);

        if ($fp === false) {
            return false;
        }

        fclose($fp);

        return true;
    }
}
