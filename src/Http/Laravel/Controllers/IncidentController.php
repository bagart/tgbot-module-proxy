<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Laravel\Controllers;

use BAGArt\ProxyOperations\Incident\IncidentDetector;
use BAGArt\ProxyOperations\Incident\IncidentEscalator;
use BAGArt\ProxyOperations\Models\Incident;
use BAGArt\ProxyOperations\Models\IncidentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Incident management controller (plan §§10.12 п.12).
 */
class IncidentController extends Controller
{
    public function __construct(
        private readonly IncidentDetector $detector,
        private readonly IncidentEscalator $escalator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $incidents = Incident::query()
            ->where('tenant_id', $request->user()->tenant_id ?? '')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json($incidents);
    }

    public function show(int $id): JsonResponse
    {
        $incident = Incident::with('actions')->findOrFail($id);

        return response()->json($incident);
    }

    public function detect(): JsonResponse
    {
        $incidents = $this->detector->detect();

        return response()->json([
            'detected' => count($incidents),
            'incidents' => $incidents,
        ]);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        $newStatus = IncidentStatus::from($request->input('status'));

        $incident = $this->escalator->transition(
            $incident,
            $newStatus,
            $request->user()->name ?? 'system',
        );

        return response()->json($incident);
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        $action = $this->escalator->addNote(
            $incident,
            $request->input('note', ''),
            $request->user()->name ?? 'system',
        );

        return response()->json($action);
    }
}
