<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Domain\Lease\LeaseState;
use BAGArt\ProxyOperations\Models\ProxyLease;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyLease>
 */
final class ProxyLeaseFactory extends Factory
{
    protected $model = ProxyLease::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'access_id' => ProxyAccessFactory::new()->create()->id,
            'holder' => uniqid('holder-', true),
            'purpose' => 'session',
            'state' => LeaseState::Active->value,
            'active_marker' => 1,
            'acquired_at' => now(),
            'expires_at' => now()->addSeconds(300),
            'released_at' => null,
            'renewals' => 0,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'state' => LeaseState::Released->value,
            'active_marker' => null,
            'released_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subSeconds(1),
        ]);
    }
}
