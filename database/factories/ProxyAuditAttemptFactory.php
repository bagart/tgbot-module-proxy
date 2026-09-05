<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Models\AuditAttemptStatus;
use BAGArt\ProxyOperations\Models\ProxyAuditAttempt;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyAuditAttempt>
 */
final class ProxyAuditAttemptFactory extends Factory
{
    protected $model = ProxyAuditAttempt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'job_id' => ProxyAuditJob::factory(),
            'attempt_no' => 1,
            'worker_node' => 'worker-1',
            'status' => AuditAttemptStatus::Pending->value,
            'result_code' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function attemptNo(int $attemptNo): static
    {
        return $this->state(fn (): array => [
            'attempt_no' => $attemptNo,
        ]);
    }

    public function withStatus(AuditAttemptStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status->value,
        ]);
    }
}
