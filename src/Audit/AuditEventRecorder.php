<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\EventEnvelope;

/**
 * Append domain/integration events inside the ingestion transaction
 * (plan §§11.9, 11.20, §11.35 п.18 — the outbox row joins the same commit;
 * dispatch is strictly post-commit). Contract introduced in T26; the
 * production implementation is T28's DbAuditEventRecorder.
 */
interface AuditEventRecorder
{
    public function record(EventEnvelope $envelope): void;
}
