<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\ControlPlaneRoute;
use BAGArt\ProxyOperations\Tool\ExecutionPlaneRoute;
use BAGArt\ProxyOperations\Tool\ProbeRunnerTransport;

it('covers the plan §11.39 п.3 transports', function (): void {
    expect(array_column(ProbeRunnerTransport::cases(), 'value'))->toBe(['unix_socket', 'https_mtls']);
});

it('covers the plan §11.39 п.4 control plane routes', function (): void {
    expect(array_column(ControlPlaneRoute::cases(), 'value'))->toBe(['health', 'ready', 'metrics', 'capabilities']);
});

it('covers the plan §11.39 п.4 execution plane routes (INV-020)', function (): void {
    expect(array_column(ExecutionPlaneRoute::cases(), 'value'))->toBe([
        'create_execution',
        'get_execution',
        'cancel_execution',
    ]);
});

it('keeps control and execution planes disjoint', function (): void {
    expect(array_intersect(
        array_column(ControlPlaneRoute::cases(), 'name'),
        array_column(ExecutionPlaneRoute::cases(), 'name'),
    ))->toBe([]);
});
