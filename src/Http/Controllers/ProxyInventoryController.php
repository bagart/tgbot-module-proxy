<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Controllers;

use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProxyInventoryController
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(): Response
    {
        $this->tenant->set(Auth::id());

        $accesses = ProxyAccess::query()
            ->with('endpoint')
            ->orderByDesc('last_checked_at')
            ->paginate(50)
            ->through(fn (ProxyAccess $access) => [
                'id' => $access->id,
                'protocol' => $access->endpoint?->protocol->value ?? 'unknown',
                'host' => $this->maskHost($access->endpoint?->host ?? ''),
                'port' => $access->endpoint?->port ?? 0,
                'state' => $access->state->value,
                'quarantined' => $access->quarantine_status->value === 'quarantined',
                'lastChecked' => $access->last_checked_at?->toIso8601String(),
            ]);

        $this->tenant->forget();

        return Inertia::render('proxy/inventory', [
            'items' => $accesses->items(),
            'total' => $accesses->total(),
        ]);
    }

    public function destroy(string $endpoint): RedirectResponse
    {
        $this->tenant->set(Auth::id());

        ProxyEndpoint::query()->where('id', $endpoint)->delete();

        $this->tenant->forget();

        return redirect()->route('proxy.inventory.index');
    }

    private function maskHost(string $host): string
    {
        if ($host === '' || $host === '127.0.0.1' || $host === '::1') {
            return $host;
        }

        $parts = explode('.', $host);
        if (count($parts) >= 2) {
            $parts[count($parts) - 1] = '***';
        }

        return implode('.', $parts);
    }
}
