<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Models\ProxyEndpoint;
use BAGArt\ProxyOperations\Models\RawFeedEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RawFeedEntry>
 */
final class RawFeedEntryFactory extends Factory
{
    protected $model = RawFeedEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $rawLine = '1.2.3.4:1080';

        return [
            'import_batch_id' => $this->faker->uuid(),
            'batch_hash' => hash('sha256', strtolower(trim($rawLine))),
            'raw_line' => $rawLine,
            'line_number' => 1,
            'status' => 'pending',
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => 'pending',
            'parsed_entry_json' => null,
            'parse_error_json' => null,
        ]);
    }

    public function parsed(): static
    {
        return $this->state(fn (): array => [
            'status' => 'parsed',
            'parsed_entry_json' => ['host' => '1.2.3.4', 'port' => 1080],
            'parse_error_json' => null,
        ]);
    }

    public function withEndpoint(): static
    {
        return $this->state(fn (): array => [
            'endpoint_id' => ProxyEndpoint::factory(),
        ]);
    }
}
