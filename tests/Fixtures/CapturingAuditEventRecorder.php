<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Fixtures;

use BAGArt\ProxyOperations\Audit\AuditEventRecorder;
use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;
use Illuminate\Support\Facades\DB;

/**
 * Capturing AuditEventRecorder fake for ingestion tests: records every
 * envelope together with the DB transaction depth at record time, so tests
 * can assert events are appended inside the ingestion transaction.
 */
final class CapturingAuditEventRecorder implements AuditEventRecorder
{
    /** @var list<array{envelope: EventEnvelope, transactionLevel: int}> */
    public array $records = [];

    public function record(EventEnvelope $envelope): void
    {
        $this->records[] = [
            'envelope' => $envelope,
            'transactionLevel' => DB::transactionLevel(),
        ];
    }
}
