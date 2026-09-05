<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyHealth;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyHealth>
 */
final class ProxyHealthFactory extends Factory
{
    protected $model = ProxyHealth::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'access_id' => ProxyAccess::factory(),
            'health_score' => null,
            'capability_score' => null,
            'target_health' => null,
            'latency_percentiles' => null,
            'anonymity_tier' => null,
            'anonymity_classifier_version' => null,
            'health_formula_version' => 'v1',
            'fresh_until' => now()->addMinutes(15),
            'computed_at' => now(),
        ];
    }

    public function healthy(): static
    {
        return $this->state(fn (): array => [
            'health_score' => 90,
            'capability_score' => 80,
            'target_health' => [['target' => 'http://judge.example.internal', 'score' => 90]],
            'latency_percentiles' => ['p50' => 120, 'p95' => 300, 'p99' => 500, 'jitter_ms' => 40],
            'anonymity_tier' => 'anonymous',
            'anonymity_classifier_version' => 'v1',
        ]);
    }
}
