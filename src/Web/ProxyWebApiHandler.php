<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Web;

use BAGArt\ProxyOperations\Bot\ProxyInventorySummary;
use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Models\ProxyPolicy;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\TelegramBotMenu\Contracts\TgWebApiHandlerContract;
use BAGArt\TelegramBotMenu\Manifest\ChatScope;
use BAGArt\TelegramBotMenu\Manifest\EffectiveRole;
use BAGArt\TelegramBotMenu\Support\TgWebApiRoute;
use BAGArt\TelegramBotMenu\Support\TgWebRequest;
use BAGArt\TelegramBotMenu\Support\TgWebResponse;
use Illuminate\Support\Str;
use Throwable;

/**
 * Proxy Mini App webApi surface. Routes game actions through the chunk
 * protocol (bridge.fetch) to the same domain layer used by the web admin.
 * Identity comes exclusively from the injected TgUiContext.
 */
final readonly class ProxyWebApiHandler implements TgWebApiHandlerContract
{
    /** @return list<TgWebApiRoute> */
    public static function routes(): array
    {
        return [
            new TgWebApiRoute('GET', 'inventory', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('GET', 'pools', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('POST', 'pools', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('POST', 'pools/{id}/materialize', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('DELETE', 'pools/{id}', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('GET', 'settings', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('PUT', 'settings', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('GET', 'jobs', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('POST', 'jobs', EffectiveRole::Admin, chatScope: ChatScope::Optional),
            new TgWebApiRoute('GET', 'endpoints', EffectiveRole::Admin, chatScope: ChatScope::Optional),
        ];
    }

    /**
     * @param  list<string>  $path remainder after /tgapp/api/v1/m/proxy/
     */
    public function handle(TgWebRequest $request, array $path): TgWebResponse
    {
        return match (true) {
            $path === ['inventory'] => $this->inventory($request),
            $path === ['pools'] && $request->payload['_method'] === 'DELETE' => $this->deletePool($request),
            $path === ['pools'] && $request->payload['_method'] === 'POST' => $this->createPool($request),
            $path === ['pools'] => $this->listPools($request),
            count($path) === 3 && $path[0] === 'pools' && $path[2] === 'materialize' => $this->materializePool($request, $path[1]),
            count($path) === 2 && $path[0] === 'pools' => $this->deletePool($request, $path[1]),
            $path === ['settings'] && $request->payload['_method'] === 'PUT' => $this->updateSettings($request),
            $path === ['settings'] => $this->getSettings($request),
            $path === ['jobs'] && $request->payload['_method'] === 'POST' => $this->triggerJob($request),
            $path === ['jobs'] => $this->listJobs($request),
            $path === ['endpoints'] => $this->listEndpoints($request),
            default => TgWebResponse::error('not_found', 'Unknown proxy route.', 404, $request->requestId),
        };
    }

    private function inventory(TgWebRequest $request): TgWebResponse
    {
        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);
            $summary = ProxyInventorySummary::take();
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Inventory is not available.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok($summary);
    }

    private function listPools(TgWebRequest $request): TgWebResponse
    {
        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);
            $pools = ProxyPool::query()
                ->select('id', 'name', 'kind', 'enabled', 'description', 'last_materialized_at')
                ->orderBy('name')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'kind' => $p->kind->value,
                    'enabled' => $p->enabled,
                    'description' => $p->description,
                    'last_materialized_at' => $p->last_materialized_at?->toIso8601String(),
                ]);
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Pools unavailable.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok(['pools' => $pools]);
    }

    private function createPool(TgWebRequest $request): TgWebResponse
    {
        $tenant = app(TenantContext::class);
        $data = $request->payload;

        $name = $data['name'] ?? null;
        $kind = $data['kind'] ?? null;

        if (! is_string($name) || $name === '' || ! is_string($kind)) {
            return TgWebResponse::error('bad_request', 'name and kind are required.', 400, $request->requestId);
        }

        try {
            $tenant->set($request->context->user->id);
            $pool = ProxyPool::create([
                'name' => $name,
                'kind' => $kind,
                'description' => $data['description'] ?? null,
                'enabled' => $data['enabled'] ?? true,
            ]);
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Could not create pool.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok(['id' => $pool->id, 'name' => $pool->name]);
    }

    private function deletePool(TgWebRequest $request, ?string $id = null): TgWebResponse
    {
        $id ??= $request->payload['id'] ?? null;

        if (! is_string($id) || $id === '') {
            return TgWebResponse::error('bad_request', 'pool id is required.', 400, $request->requestId);
        }

        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);
            $deleted = ProxyPool::query()->where('id', $id)->delete();
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Could not delete pool.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        if ($deleted === 0) {
            return TgWebResponse::error('not_found', 'Pool not found.', 404, $request->requestId);
        }

        return TgWebResponse::ok(['deleted' => true]);
    }

    private function materializePool(TgWebRequest $request, string $id): TgWebResponse
    {
        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);
            $pool = ProxyPool::query()->find($id);

            if ($pool === null) {
                return TgWebResponse::error('not_found', 'Pool not found.', 404, $request->requestId);
            }

            $pool->update([
                'last_materialized_at' => now(),
                'last_materialization_id' => Str::uuid(),
            ]);
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Could not materialize pool.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok(['materialized' => true, 'pool_id' => $pool->id]);
    }

    private function getSettings(TgWebRequest $request): TgWebResponse
    {
        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);
            $policy = ProxyPolicy::forCurrentTenant();
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Settings unavailable.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok([
            'version' => $policy->version,
            'quotas' => $policy->quotas,
            'politeness' => $policy->politeness,
            'retention' => $policy->retention,
            'export_rules' => $policy->export_rules,
            'ui_flags' => $policy->ui_flags,
        ]);
    }

    private function updateSettings(TgWebRequest $request): TgWebResponse
    {
        $tenant = app(TenantContext::class);
        $data = $request->payload;

        try {
            $tenant->set($request->context->user->id);
            $policy = ProxyPolicy::forCurrentTenant();

            $updateData = array_intersect_key($data, array_flip([
                'quotas', 'politeness', 'retention', 'export_rules', 'ui_flags',
            ]));

            $policy->update($updateData);
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Could not update settings.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok(['updated' => true]);
    }

    private function listJobs(TgWebRequest $request): TgWebResponse
    {
        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);
            $jobs = ProxyAuditJob::query()
                ->select('id', 'trigger', 'status', 'result_code', 'started_at', 'completed_at', 'created_at')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Jobs unavailable.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok(['jobs' => $jobs]);
    }

    private function triggerJob(TgWebRequest $request): TgWebResponse
    {
        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);

            $job = ProxyAuditJob::create([
                'trigger' => 'manual',
                'status' => AuditJobStatus::Pending,
                'requested_by' => $request->tgUserId,
                'target_set_hash' => hash('sha256', 'manual-'.time()),
            ]);
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Could not trigger job.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok(['job_id' => $job->id, 'status' => 'pending']);
    }

    private function listEndpoints(TgWebRequest $request): TgWebResponse
    {
        $tenant = app(TenantContext::class);

        try {
            $tenant->set($request->context->user->id);
            $endpoints = ProxyEndpoint::query()
                ->select('id', 'protocol', 'host', 'port', 'canonical_host', 'created_at')
                ->orderByDesc('created_at')
                ->limit(50)
                ->get()
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'protocol' => $e->protocol,
                    'host' => self::maskHost($e->host),
                    'port' => $e->port,
                    'created_at' => $e->created_at->toIso8601String(),
                ]);
        } catch (Throwable) {
            return TgWebResponse::error('internal', 'Endpoints unavailable.', 500, $request->requestId);
        } finally {
            $tenant->forget();
        }

        return TgWebResponse::ok(['endpoints' => $endpoints]);
    }

    private static function maskHost(string $host): string
    {
        $parts = explode('.', $host);

        if (count($parts) <= 2) {
            return $parts[0][0].'***.'.$parts[count($parts) - 1];
        }

        $parts[0] = $parts[0][0].'***';

        return implode('.', $parts);
    }
}
