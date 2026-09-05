<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Models\ProxySource;
use BAGArt\ProxyOperations\Models\SourceKind;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxySource>
 */
final class ProxySourceFactory extends Factory
{
    protected $model = ProxySource::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'kind' => SourceKind::Paste,
            'label' => fake()->optional()->words(asText: true),
            'feed_id' => null,
            'import_policy_version' => null,
            'enabled' => true,
            'last_synced_at' => null,
        ];
    }

    public function feed(): static
    {
        return $this->state(fn (): array => [
            'kind' => SourceKind::Feed,
            'feed_id' => fake()->uuid(),
            'import_policy_version' => 'v1',
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => [
            'enabled' => false,
        ]);
    }
}
