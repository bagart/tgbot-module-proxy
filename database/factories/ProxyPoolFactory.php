<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Domain\Pool\PoolKind;
use BAGArt\ProxyOperations\Models\ProxyPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyPool>
 */
final class ProxyPoolFactory extends Factory
{
    protected $model = ProxyPool::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => uniqid('pool-', true),
            'kind' => PoolKind::Static->value,
            'predicate' => null,
            'enabled' => true,
            'description' => null,
            'policy_version' => 1,
        ];
    }

    public function dynamic(): static
    {
        return $this->state(fn (): array => [
            'kind' => PoolKind::Dynamic->value,
            'predicate' => [
                'states' => ['working'],
                'minHealthScore' => 70.0,
                'schemaVersion' => 1,
            ],
        ]);
    }

    public function hybrid(): static
    {
        return $this->state(fn (): array => [
            'kind' => PoolKind::Hybrid->value,
            'predicate' => [
                'states' => ['working'],
                'schemaVersion' => 1,
            ],
        ]);
    }
}
