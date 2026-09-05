<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Models\ProxyPool;
use BAGArt\ProxyOperations\Models\ProxyPoolMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyPoolMember>
 */
final class ProxyPoolMemberFactory extends Factory
{
    protected $model = ProxyPoolMember::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'pool_id' => ProxyPool::factory(),
            'access_id' => ProxyAccessFactory::new()->create()->id,
            'added_at' => now(),
            'materialization_version' => null,
        ];
    }

    public function materialized(int $version): static
    {
        return $this->state(fn (): array => [
            'materialization_version' => $version,
        ]);
    }
}
