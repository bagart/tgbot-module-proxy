<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

/**
 * Lifecycle of a single audit attempt (plan §11.18): one execution try of a
 * job, from delivery through running into a terminal completed/failed state or
 * the lost state (worker never reported back).
 */
enum AuditAttemptStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Lost = 'lost';
}
