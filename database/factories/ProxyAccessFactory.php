<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use BAGArt\ProxyOperations\Domain\Lifecycle\QuarantineStatus;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyAccess>
 */
final class ProxyAccessFactory extends Factory
{
    protected $model = ProxyAccess::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'endpoint_id' => ProxyEndpoint::factory(),
            // Bound to the same endpoint as the access itself; the creating
            // hook derives access_identity_hash from endpoint + fingerprint.
            'credential_id' => fn (array $attributes): string => ProxyCredential::factory()
                ->create(['endpoint_id' => $attributes['endpoint_id']])
                ->id,
        ];
    }

    public function credentialFree(): static
    {
        return $this->state(fn (): array => [
            'credential_id' => null,
        ]);
    }

    public function working(): static
    {
        return $this->state(fn (): array => [
            'state' => AccessState::Working->value,
            'telegram_connectivity' => true,
            'telegram_usable' => true,
            'telegram_checked_at' => now(),
            'telegram_fresh_until' => now()->addMinutes(10),
            'telegram_evidence_version' => 'v1',
        ]);
    }

    public function degraded(): static
    {
        return $this->state(fn (): array => [
            'state' => AccessState::Degraded->value,
        ]);
    }

    public function dead(): static
    {
        return $this->state(fn (): array => [
            'state' => AccessState::Dead->value,
        ]);
    }

    public function quarantined(string $reason): static
    {
        return $this->state(fn (): array => [
            'quarantine_status' => QuarantineStatus::Quarantined->value,
            'quarantine_reason' => $reason,
        ]);
    }
}
