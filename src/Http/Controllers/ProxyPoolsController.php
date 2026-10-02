<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Http\Controllers;

use BAGArt\ProxyOperations\Audit\PoolMaterializer;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class ProxyPoolsController
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {
    }

    public function index(): Response
    {
        $this->tenant->set(Auth::id());

        $pools = ProxyPool::query()
            ->withCount('members')
            ->orderBy('name')
            ->get()
            ->map(fn (ProxyPool $pool) => [
                'id' => $pool->id,
                'name' => $pool->name,
                'kind' => $pool->kind->value,
                'enabled' => $pool->enabled,
                'description' => $pool->description,
                'members_count' => $pool->members_count,
                'last_materialized_at' => $pool->last_materialized_at?->toIso8601String(),
            ]);

        $this->tenant->forget();

        return Inertia::render('proxy/pools', [
            'pools' => $pools,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'kind' => 'required|in:static,dynamic,hybrid',
            'description' => 'nullable|string|max:1000',
            'predicate' => 'nullable|array',
        ]);

        $this->tenant->set(Auth::id());

        ProxyPool::query()->create([
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'description' => $validated['description'] ?? null,
            'predicate' => $validated['predicate'] ?? null,
        ]);

        $this->tenant->forget();

        return redirect()->route('proxy.pools.index');
    }

    public function update(Request $request, ProxyPool $pool): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'enabled' => 'sometimes|boolean',
            'description' => 'nullable|string|max:1000',
        ]);

        $this->tenant->set(Auth::id());

        $pool->update($validated);

        $this->tenant->forget();

        return redirect()->route('proxy.pools.index');
    }

    public function destroy(ProxyPool $pool): RedirectResponse
    {
        $this->tenant->set(Auth::id());

        $pool->members()->delete();
        $pool->delete();

        $this->tenant->forget();

        return redirect()->route('proxy.pools.index');
    }

    public function materialize(ProxyPool $pool): RedirectResponse
    {
        $this->tenant->set(Auth::id());

        $materializer = app(PoolMaterializer::class);
        $materializer->materialize($pool);

        $this->tenant->forget();

        return redirect()->route('proxy.pools.index');
    }
}
