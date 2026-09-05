<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Models\ProxyAccess;
use BAGArt\ProxyOperations\Models\ProxyObservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ProxyObservation>
 */
final class ProxyObservationFactory extends Factory
{
    protected $model = ProxyObservation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'access_id' => ProxyAccess::factory(),
            'checked_at' => now(),
            'probe_type' => ProbeType::HttpLiveness->value,
            'probe_profile' => ProbeProfile::Standard->value,
            'probe_profile_version' => 'v1',
            // R6.4 allowlisted raw measurements only.
            'outcome' => 'success',
            'evidence' => [
                'status' => 200,
                'content_length' => 1284,
                'body_hash' => 'sha256:'.str_repeat('ab', 32),
                'bytes_received' => 1284,
                'timings' => ['connect_ms' => 120, 'ttfb_ms' => 340, 'total_ms' => 610],
            ],
            'schema_version' => ProxyObservation::SCHEMA_VERSION,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (): array => [
            'outcome' => 'success',
            'failure_code' => null,
            'failure_class' => null,
        ]);
    }

    public function failed(FailureCode $code): static
    {
        return $this->state(fn (): array => [
            'outcome' => 'failure',
            'failure_code' => $code->value,
            'failure_class' => $code->class()->value,
        ]);
    }

    public function checkedAt(Carbon $at): static
    {
        return $this->state(fn (): array => [
            'checked_at' => $at,
        ]);
    }
}
