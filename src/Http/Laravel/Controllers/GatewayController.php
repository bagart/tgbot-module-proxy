<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Controllers;

use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportProxiesHandler;
use BAGArt\ProxyOperations\Application\ExportInventoryCommand;
use BAGArt\ProxyOperations\Application\ExportInventoryHandler;
use BAGArt\ProxyOperations\Audit\LeaseService;
use BAGArt\ProxyOperations\Audit\ResultIngestionService;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * Gateway API controller (plan §§10.12 п.14, 11.10).
 * Versioned, additive-only contract for external consumers.
 */
class GatewayController extends Controller
{
    public function __construct(
        private readonly ImportProxiesHandler $importHandler,
        private readonly ExportInventoryHandler $exportHandler,
        private readonly LeaseService $leases,
        private readonly ResultIngestionService $ingestion,
    ) {}

    /**
     * GET /api/v1/proxies — list proxies
     */
    public function listProxies(Request $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant_id');
        $format = $request->input('format');
        $pool = $request->input('pool');
        $limit = min((int) $request->input('limit', 50), 200);

        $query = ProxyEndpoint::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);

        if ($format !== null) {
            $query->where('format', $format);
        }

        if ($pool !== null) {
            $query->where('pool_name', $pool);
        }

        $proxies = $query->limit($limit)->get();

        return response()->json([
            'proxies' => $proxies->map(fn ($p) => [
                'id' => $p->id,
                'format' => $p->format,
                'host' => $p->host,
                'port' => $p->port,
                'country' => $p->country,
                'health_score' => $p->health_score,
                'last_checked_at' => $p->last_checked_at?->toISOString(),
            ]),
            'count' => $proxies->count(),
        ]);
    }

    /**
     * POST /api/v1/proxies/import — import proxies
     */
    public function importProxies(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'proxies' => 'required|array|min:1',
            'proxies.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $command = new ImportProxiesCommand(
            rawLines: $request->input('proxies'),
            tenantId: $request->attributes->get('tenant_id'),
            source: \BAGArt\ProxyOperations\Application\ImportSource::Api,
        );

        $result = $this->importHandler->handle($command);

        return response()->json([
            'imported' => $result->imported,
            'skipped' => $result->skipped,
            'errors' => $result->errors,
        ], 201);
    }

    /**
     * POST /api/v1/proxies/assign — assign a proxy (lease)
     */
    public function assignProxy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'endpoint_id' => 'required|integer',
            'ttl_seconds' => 'nullable|integer|min:1|max:3600',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $tenantId = $request->attributes->get('tenant_id');
        $endpointId = (int) $request->input('endpoint_id');
        $ttl = (int) $request->input('ttl_seconds', 300);

        $lease = $this->leases->acquire(
            tenantId: $tenantId,
            endpointId: $endpointId,
            ttlSeconds: $ttl,
        );

        if ($lease === null) {
            return response()->json(['error' => 'Proxy unavailable or lease limit reached'], 409);
        }

        $endpoint = ProxyEndpoint::find($endpointId);

        return response()->json([
            'access_id' => $lease->accessId,
            'endpoint' => [
                'id' => $endpoint?->id,
                'host' => $endpoint?->host,
                'port' => $endpoint?->port,
                'format' => $endpoint?->format,
            ],
            'expires_at' => $lease->expiresAt->toISOString(),
        ]);
    }

    /**
     * POST /api/v1/proxies/release — release a lease
     */
    public function releaseProxy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_id' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $released = $this->leases->release($request->input('access_id'));

        return response()->json(['released' => $released]);
    }

    /**
     * POST /api/v1/proxies/report — report check result
     */
    public function reportResult(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'access_id' => 'required|string',
            'success' => 'required|boolean',
            'latency_ms' => 'nullable|integer',
            'error' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $this->ingestion->ingest(
            accessId: $request->input('access_id'),
            success: $request->boolean('success'),
            latencyMs: $request->input('latency_ms'),
            error: $request->input('error'),
        );

        return response()->json(['recorded' => true]);
    }
}
