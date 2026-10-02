<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Controllers;

use BAGArt\ProxyOperations\Decision\DecisionLogService;
use BAGArt\ProxyOperations\Models\DecisionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Decision log controller (plan §§10.12 п.13, 11.10).
 * Query API for decision history, timeline, and filtering.
 */
class DecisionController extends Controller
{
    public function __construct(
        private readonly DecisionLogService $decisionLog,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? '';
        $type = $request->has('type') ? DecisionType::from($request->input('type')) : null;
        $entityType = $request->input('entity_type');
        $entityId = $request->has('entity_id') ? (int) $request->input('entity_id') : null;
        $from = $request->input('from');
        $to = $request->input('to');
        $perPage = (int) $request->input('per_page', 50);

        $decisions = $this->decisionLog->query(
            tenantId: $tenantId,
            type: $type,
            entityType: $entityType,
            entityId: $entityId,
            from: $from,
            to: $to,
            perPage: $perPage,
        );

        return response()->json($decisions);
    }

    public function timeline(Request $request, string $entityType, int $entityId): JsonResponse
    {
        $tenantId = $request->user()->tenant_id ?? '';

        $timeline = $this->decisionLog->timeline(
            tenantId: $tenantId,
            entityType: $entityType,
            entityId: $entityId,
        );

        return response()->json(['timeline' => $timeline]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'decision_type' => 'required|string',
            'entity_type' => 'required|string',
            'entity_id' => 'nullable|integer',
            'action' => 'required|string',
            'reason' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $decision = $this->decisionLog->record(
            tenantId: $request->user()->tenant_id ?? '',
            type: DecisionType::from($validated['decision_type']),
            entityType: $validated['entity_type'],
            entityId: $validated['entity_id'] ?? null,
            action: $validated['action'],
            reason: $validated['reason'] ?? '',
            metadata: $validated['metadata'] ?? [],
            userId: $request->user()->id ?? null,
        );

        return response()->json($decision, 201);
    }
}
