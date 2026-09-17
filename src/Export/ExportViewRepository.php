<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use Illuminate\Support\Facades\DB;

/**
 * Loads export views from DB: ProxyAccess + Endpoint + Credential (masked) + Health.
 * Tenant-scoped by default.
 */
final class ExportViewRepository
{
    /**
     * @return list<ExportView>
     */
    public function load(ExportQuery $query): array
    {
        $qb = DB::table('proxy_accesses as a')
            ->join('proxy_endpoints as e', 'a.endpoint_id', '=', 'e.id')
            ->leftJoin('proxy_credentials as c', 'a.credential_id', '=', 'c.id')
            ->leftJoin('proxy_health as h', 'h.access_id', '=', 'a.id')
            ->where('a.tenant_id', $query->tenantId);

        if ($query->accessFilters !== []) {
            $qb->whereIn('a.id', $query->accessFilters);
        }

        if ($query->tgReadyOnly) {
            $qb->where('a.telegram_usable', true);
        }

        if ($query->poolId !== null) {
            $qb->join('proxy_pool_members as pm', function ($join) use ($query): void {
                $join->on('pm.access_id', '=', 'a.id')
                    ->where('pm.pool_id', '=', $query->poolId);
            });
        }

        $rows = $qb->select(
            'a.id as access_id',
            'e.protocol',
            'e.host',
            'e.port',
            'c.masked_representation',
            'h.health_score',
            'a.state',
            'a.telegram_usable',
        )->get();

        $views = [];

        foreach ($rows as $row) {
            $views[] = new ExportView(
                accessId: $row->access_id,
                protocol: ProxyProtocol::from($row->protocol),
                host: $row->host,
                port: (int) $row->port,
                credential: (string) ($row->masked_representation ?? ''),
                healthScore: $row->health_score !== null ? (float) $row->health_score : null,
                accessState: $row->state,
                telegramUsable: $row->telegram_usable !== null ? (bool) $row->telegram_usable : null,
                country: null,
            );
        }

        return $views;
    }
}
