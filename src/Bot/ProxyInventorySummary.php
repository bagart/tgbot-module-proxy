<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Bot;

use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\QuarantineStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Tenancy\TenantContext;

/**
 * Tenant-scoped inventory read for the bot card and the menu-hub webApi
 * route (menu_integration.md M-6 slice 2). Masked summary only — hosts,
 * credentials and evidence never cross the surface (module hard rule).
 * The caller is responsible for setting/forgetting the TenantContext.
 */
final readonly class ProxyInventorySummary
{
    /**
     * Query methods run under the tenant global scope (BelongsToTenant), so
     * the tenant context MUST be set before calling.
     *
     * @return array{endpoints: int, states: array<string, int>, quarantined: int}
     */
    public static function take(): array
    {
        $endpoints = ProxyEndpoint::query()->count();

        $states = [];
        foreach (AccessState::cases() as $state) {
            $states[$state->value] = 0;
        }

        $quarantined = 0;
        ProxyAccess::query()->chunkById(500, function ($accesses) use (&$states, &$quarantined): void {
            foreach ($accesses as $access) {
                $states[$access->state->value] = ($states[$access->state->value] ?? 0) + 1;
                if ($access->quarantine_status === QuarantineStatus::Quarantined) {
                    $quarantined++;
                }
            }
        });

        return [
            'endpoints' => $endpoints,
            'states' => $states,
            'quarantined' => $quarantined,
        ];
    }

    /** Plain-text card for the /proxy command — no hosts, counts only. */
    public static function toText(array $summary): string
    {
        $states = $summary['states'];
        $lines = [
            'Proxy inventory',
            sprintf('Endpoints: %d', $summary['endpoints']),
            sprintf('Working: %d, degraded: %d, failing: %d', $states[AccessState::Working->value], $states[AccessState::Degraded->value], $states[AccessState::Failing->value]),
            sprintf('Quarantined: %d', $summary['quarantined']),
        ];

        return implode("\n", $lines);
    }
}
