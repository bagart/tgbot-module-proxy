<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

/**
 * Execution plane endpoints of the runner (plan §11.39 пп.4,18; INV-020):
 * create/get/cancel over the single Probe Execution protocol — one protocol,
 * many runners behind it.
 */
enum ExecutionPlaneRoute: string
{
    case CreateExecution = 'create_execution';
    case GetExecution = 'get_execution';
    case CancelExecution = 'cancel_execution';
}
