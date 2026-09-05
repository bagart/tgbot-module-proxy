<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Models\ProxyCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyCredential>
 */
final class ProxyCredentialFactory extends Factory
{
    protected $model = ProxyCredential::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => CredentialKind::SocksAuth,
            'username' => fake()->userName(),
            // Transient plaintext: consumed by the model to derive the
            // fingerprint and masked representation, never persisted.
            'secret' => fake()->password(16),
        ];
    }

    public function socksAuth(): static
    {
        return $this->state(fn (): array => [
            'kind' => CredentialKind::SocksAuth,
        ]);
    }

    public function basicAuth(): static
    {
        return $this->state(fn (): array => [
            'kind' => CredentialKind::BasicAuth,
        ]);
    }

    public function mtprotoSecret(): static
    {
        return $this->state(fn (): array => [
            'kind' => CredentialKind::MtprotoSecret,
            'username' => null,
            'secret' => bin2hex(random_bytes(16)),
        ]);
    }
}
