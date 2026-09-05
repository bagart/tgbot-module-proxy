<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyCapability;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyCapability>
 */
final class ProxyCapabilityFactory extends Factory
{
    protected $model = ProxyCapability::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'endpoint_id' => ProxyEndpoint::factory(),
            // NULL = endpoint-level capability row (plan §11.21).
            'access_id' => null,
            'udp_associate_supported' => null,
            'dns_resolution_mode' => null,
            'matrix' => [],
            'capability_formula_version' => 'v1',
            'evaluated_at' => now(),
        ];
    }

    /**
     * Access-level capability part (auth/udp/dns/tg): binds the row to an
     * access of the same endpoint.
     */
    public function accessLevel(): static
    {
        return $this->state(fn (): array => [
            // Lazy resolver: runs after endpoint_id expansion so the access
            // lands on the same endpoint as the capability row itself.
            'access_id' => fn (array $attributes): string => ProxyAccess::factory()
                ->credentialFree()
                ->create(['endpoint_id' => $attributes['endpoint_id']])
                ->id,
            'udp_associate_supported' => true,
            'dns_resolution_mode' => 'REMOTE_DNS',
        ]);
    }

    public function socks5Matrix(): static
    {
        return $this->state(fn (): array => [
            'matrix' => [
                'capabilities' => ['http_targets', 'https_via_connect', 'udp_associate', 'remote_dns', 'telegram_connectivity'],
                'transports' => ['tcp_direct', 'tcp_via_proxy', 'udp_associate', 'dns_modes'],
            ],
        ]);
    }
}
