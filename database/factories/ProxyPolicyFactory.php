<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Models\ProxyPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyPolicy>
 */
final class ProxyPolicyFactory extends Factory
{
    protected $model = ProxyPolicy::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ProxyPolicy::defaultsForTenant();
    }
}
