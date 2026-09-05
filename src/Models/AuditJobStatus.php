<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

/**
 * Lifecycle of a logical audit job (plan §11.18): from placement (pending)
 * through queued/running into a terminal completed/failed/cancelled state.
 */
enum AuditJobStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
