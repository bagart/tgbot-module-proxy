<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyEndpoint>
 */
final class ProxyEndpointFactory extends Factory
{
    protected $model = ProxyEndpoint::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'protocol' => ProxyProtocol::Socks5,
            'host' => fake()->ipv4(),
            'port' => 1080,
            'comment' => null,
        ];
    }

    public function http(): static
    {
        return $this->state(fn (): array => [
            'protocol' => ProxyProtocol::Http,
            'port' => 8080,
        ]);
    }

    public function mtproto(): static
    {
        return $this->state(fn (): array => [
            'protocol' => ProxyProtocol::Mtproto,
            'port' => 443,
        ]);
    }
}
