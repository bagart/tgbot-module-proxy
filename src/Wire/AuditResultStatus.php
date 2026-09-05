<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Wire;

/**
 * Terminal status of one audit attempt as reported by the worker.
 */
enum AuditResultStatus: string
{
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
}
