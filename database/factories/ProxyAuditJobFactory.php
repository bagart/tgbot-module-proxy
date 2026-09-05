<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Database\Factories;

use BAGArt\ProxyOperations\Models\AuditJobStatus;
use BAGArt\ProxyOperations\Models\AuditTrigger;
use BAGArt\ProxyOperations\Models\PolicySnapshot;
use BAGArt\ProxyOperations\Models\ProxyAuditJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProxyAuditJob>
 */
final class ProxyAuditJobFactory extends Factory
{
    protected $model = ProxyAuditJob::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'trigger' => AuditTrigger::Manual->value,
            'policy_snapshot_id' => PolicySnapshot::factory(),
            'requested_by' => null,
            'target_set_hash' => hash('sha256', 'targets-'.bin2hex(random_bytes(8))),
            'status' => AuditJobStatus::Pending->value,
            'result_code' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function withTrigger(AuditTrigger $trigger): static
    {
        return $this->state(fn (): array => [
            'trigger' => $trigger->value,
        ]);
    }

    public function withStatus(AuditJobStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status->value,
        ]);
    }

    public function completed(string $resultCode = 'OK'): static
    {
        return $this->state(fn (): array => [
            'status' => AuditJobStatus::Completed->value,
            'result_code' => $resultCode,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
