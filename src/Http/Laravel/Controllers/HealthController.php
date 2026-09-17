<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Controllers;

use BAGArt\ProxyOperations\Support\HealthCheckerContract;
use BAGArt\ProxyOperations\Transport\ResourceGovernor;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * HTTP health endpoints for the proxy module (plan §11.34 P4).
 * Accessible on the main HTTP server, not just the worker private network.
 */
class HealthController extends Controller
{
    public function __construct(
        private readonly ResourceGovernor $governor,
        private readonly HealthCheckerContract $healthChecker,
    ) {}

    /**
     * Liveness probe — returns 200 if the process is alive.
     */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /**
     * Readiness probe — returns 200 if DB + Redis are reachable.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->healthChecker->checkDatabase(),
            'redis' => $this->healthChecker->checkRedis(),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => $checks,
        ], $ready ? 200 : 503);
    }

    /**
     * Detailed health check — worker status, queue depth, cache stats.
     */
    public function detailed(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'checks' => [
                'database' => $this->healthChecker->checkDatabase(),
                'redis' => $this->healthChecker->checkRedis(),
            ],
            'worker' => [
                'active_connections' => $this->governor->activeConnections(),
                'total_bytes_in' => $this->governor->totalBytesIn(),
                'total_bytes_out' => $this->governor->totalBytesOut(),
                'can_open_connection' => $this->governor->canOpenConnection(),
            ],
        ]);
    }
}
