<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Controllers;

use BAGArt\ProxyOperations\Models\ProxyPolicy;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProxySettingsController
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(): Response
    {
        $this->tenant->set(Auth::id());

        $policy = ProxyPolicy::firstOrNew(['tenant_id' => Auth::id()]);

        $this->tenant->forget();

        return Inertia::render('proxy/settings', [
            'policy' => [
                'selection_strategy' => config('proxy-operations.audit.selection.strategy', 'round_robin'),
                'lease_ttl_seconds' => (int) config('proxy-operations.audit.leases.ttl_seconds', 300),
                'reaper_batch_size' => (int) config('proxy-operations.audit.leases.reaper_batch_size', 200),
                'max_concurrent_probes' => (int) config('proxy-operations.resource_governor.max_concurrent_probes', 50),
                'hysteresis_degrade' => (int) config('proxy-operations.audit.health.hysteresis.consecutive_failures_to_degrade', 2),
                'hysteresis_failing' => (int) config('proxy-operations.audit.health.hysteresis.consecutive_failures_to_failing', 5),
                'hysteresis_dead' => (int) config('proxy-operations.audit.health.hysteresis.consecutive_failures_to_declare_dead', 10),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'selection_strategy' => 'sometimes|in:round_robin,random,least_used,weighted',
            'lease_ttl_seconds' => 'sometimes|integer|min:60|max:3600',
            'reaper_batch_size' => 'sometimes|integer|min:10|max:1000',
            'max_concurrent_probes' => 'sometimes|integer|min:1|max:200',
            'hysteresis_degrade' => 'sometimes|integer|min:1|max:100',
            'hysteresis_failing' => 'sometimes|integer|min:1|max:100',
            'hysteresis_dead' => 'sometimes|integer|min:1|max:100',
        ]);

        return redirect()->route('proxy.settings.index');
    }
}
