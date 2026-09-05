<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

/**
 * Control plane endpoints of the runner (plan §11.39 пп.4,10; INV-020).
 * Strictly separated from ExecutionPlaneRoute: cancel is not a health check.
 * Never exposed to the internet — private docker network / mTLS only.
 */
enum ControlPlaneRoute: string
{
    case Health = 'health';
    case Ready = 'ready';
    case Metrics = 'metrics';
    case Capabilities = 'capabilities';
}
