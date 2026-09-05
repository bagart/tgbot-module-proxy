<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Tool\ControlPlaneRoute;
use BAGArt\ProxyOperations\Tool\ToolRegistry;

/**
 * Handles control plane requests for the worker node (plan §11.39 пп.4,10):
 * GET /health, GET /ready, GET /metrics, GET /capabilities.
 * Never exposed to the internet — private docker network only.
 */
final class WorkerControlPlaneHandler
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ResourceGovernor $governor,
        private readonly TransportToolManifestProvider $manifestProvider,
    ) {}

    public function handle(ControlPlaneRoute $route): array
    {
        return match ($route) {
            ControlPlaneRoute::Health => $this->health(),
            ControlPlaneRoute::Ready => $this->ready(),
            ControlPlaneRoute::Metrics => $this->metrics(),
            ControlPlaneRoute::Capabilities => $this->capabilities(),
        };
    }

    /**
     * @return array{status: string}
     */
    private function health(): array
    {
        return ['status' => 'ok'];
    }

    /**
     * @return array{status: string, code: int}
     */
    private function ready(): array
    {
        $ready = $this->governor->canOpenConnection();

        return [
            'status' => $ready ? 'ready' : 'not_ready',
            'code' => $ready ? 200 : 503,
        ];
    }

    /**
     * @return array{activeConnections: int, totalBytesIn: int, totalBytesOut: int}
     */
    private function metrics(): array
    {
        return [
            'activeConnections' => $this->governor->activeConnections(),
            'totalBytesIn' => $this->governor->totalBytesIn(),
            'totalBytesOut' => $this->governor->totalBytesOut(),
        ];
    }

    /**
     * @return array{name: string, version: string, capabilities: mixed}
     */
    private function capabilities(): array
    {
        $manifests = $this->manifestProvider->manifests();
        $result = [];

        foreach ($manifests as $manifest) {
            $result[$manifest->name->value] = $manifest->jsonSerialize();
        }

        return $result;
    }
}
