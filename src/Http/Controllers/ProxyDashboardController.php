<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Controllers;

use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProxyDashboardController
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(): Response
    {
        $this->tenant->set(Auth::id());

        $total = ProxyEndpoint::query()->count();
        $quarantined = ProxyAccess::query()->where('quarantine_status', 'quarantined')->count();
        $states = ProxyAccess::query()
            ->selectRaw('state, count(*) as cnt')
            ->groupBy('state')
            ->pluck('cnt', 'state')
            ->all();

        $this->tenant->forget();

        return Inertia::render('proxy/dashboard', [
            'inventory' => [
                'endpoints' => $total,
                'states' => $states,
                'quarantined' => $quarantined,
            ],
        ]);
    }
}
