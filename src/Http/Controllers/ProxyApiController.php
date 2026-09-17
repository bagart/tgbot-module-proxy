<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Controllers;

use BAGArt\ProxyOperations\Application\AuditStatusHandler;
use BAGArt\ProxyOperations\Application\AuditStatusQuery;
use BAGArt\ProxyOperations\Application\ExportInventoryCommand;
use BAGArt\ProxyOperations\Application\ExportInventoryHandler;
use BAGArt\ProxyOperations\Application\ImportProxiesCommand;
use BAGArt\ProxyOperations\Application\ImportProxiesHandler;
use BAGArt\ProxyOperations\Application\ImportSource;
use BAGArt\ProxyOperations\Application\StartAuditCommand;
use BAGArt\ProxyOperations\Application\StartAuditHandler;
use BAGArt\ProxyOperations\Application\UpdateSettingsCommand;
use BAGArt\ProxyOperations\Application\UpdateSettingsHandler;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsHandler;
use BAGArt\ProxyOperations\Application\WorkspaceSettingsQuery;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\ProxyPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Versioned API v1 controller (plan §11.12).
 */
class ProxyApiController extends Controller
{
    public function listProxies(Request $request): JsonResponse
    {
        $endpoints = ProxyEndpoint::query()
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $endpoints->items(),
            'meta' => [
                'current_page' => $endpoints->currentPage(),
                'last_page' => $endpoints->lastPage(),
                'per_page' => $endpoints->perPage(),
                'total' => $endpoints->total(),
            ],
        ]);
    }

    public function importProxy(
        Request $request,
        ImportProxiesHandler $handler,
    ): JsonResponse {
        $validated = $request->validate([
            'payload' => 'required|string',
            'source' => 'required|in:paste,file,feed',
            'source_label' => 'nullable|string',
        ]);

        $command = new ImportProxiesCommand(
            tenantId: (string) $request->user()?->id ?? '1',
            source: ImportSource::from($validated['source']),
            payload: $validated['payload'],
            sourceLabel: $validated['source_label'] ?? null,
        );

        $result = $handler->handle($command);

        return response()->json([
            'success' => $result->success,
            'message' => $result->messageKey,
            'data' => $result->data,
        ], $result->success ? 200 : 422);
    }

    public function getProxy(string $id): JsonResponse
    {
        $endpoint = ProxyEndpoint::find($id);

        if ($endpoint === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(['data' => $endpoint]);
    }

    public function deleteProxy(string $id): JsonResponse
    {
        $endpoint = ProxyEndpoint::find($id);

        if ($endpoint === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $endpoint->delete();

        return response()->json(['success' => true]);
    }

    public function exportProxies(
        Request $request,
        ExportInventoryHandler $handler,
    ): JsonResponse {
        $validated = $request->validate([
            'format' => 'required|string|in:json,csv,txt,txt_scheme,txt_full,proxychains,curl,clash,tg',
            'pool_id' => 'nullable|string',
            'include_credentials' => 'boolean',
            'tg_ready_only' => 'boolean',
        ]);

        $command = new ExportInventoryCommand(
            tenantId: (string) $request->user()?->id ?? '1',
            format: $validated['format'],
            poolId: $validated['pool_id'] ?? null,
            includeCredentials: $validated['include_credentials'] ?? false,
            tgReadyOnly: $validated['tg_ready_only'] ?? false,
            requestedBy: 'api',
        );

        $result = $handler->handle($command);

        return response()->json([
            'success' => $result->success,
            'data' => $result->data,
        ]);
    }

    public function startAudit(
        Request $request,
        StartAuditHandler $handler,
    ): JsonResponse {
        $validated = $request->validate([
            'target_access_ids' => 'nullable|array',
            'target_access_ids.*' => 'string',
        ]);

        $command = new StartAuditCommand(
            tenantId: (string) $request->user()?->id ?? '1',
            targetAccessIds: $validated['target_access_ids'] ?? [],
            requestedBy: 'api',
        );

        $result = $handler->handle($command);

        return response()->json([
            'success' => $result->success,
            'data' => $result->data,
        ], $result->success ? 202 : 422);
    }

    public function auditStatus(
        string $jobId,
        AuditStatusHandler $handler,
    ): JsonResponse {
        $query = new AuditStatusQuery(
            tenantId: request()->user()?->id ?? '1',
            jobId: $jobId,
        );

        $result = $handler->handle($query);

        return response()->json([
            'found' => $result->found,
            'data' => $result->data,
        ], $result->found ? 200 : 404);
    }

    public function listPools(Request $request): JsonResponse
    {
        $pools = ProxyPool::where('tenant_id', $request->user()?->id ?? '1')
            ->withCount('members')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $pools]);
    }

    public function getSettings(
        Request $request,
        WorkspaceSettingsHandler $handler,
    ): JsonResponse {
        $query = new WorkspaceSettingsQuery(
            tenantId: (string) $request->user()?->id ?? '1',
        );

        $result = $handler->handle($query);

        return response()->json(['data' => $result->data]);
    }

    public function updateSettings(
        Request $request,
        UpdateSettingsHandler $handler,
    ): JsonResponse {
        $validated = $request->validate([
            'field' => 'required|string',
            'value' => 'required',
        ]);

        $command = new UpdateSettingsCommand(
            tenantId: (string) $request->user()?->id ?? '1',
            field: $validated['field'],
            value: $validated['value'],
            updatedBy: 'api',
        );

        $result = $handler->handle($command);

        return response()->json([
            'success' => $result->success,
            'data' => $result->data,
        ], $result->success ? 200 : 422);
    }
}
