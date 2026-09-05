<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

/**
 * What initiated an audit job (plan §11.18): manual workspace run, scheduler
 * pass, import, feed, lazy selection, recovery or a Telegram check.
 */
enum AuditTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';
    case Import = 'import';
    case Feed = 'feed';
    case LazySelection = 'lazy_selection';
    case Recovery = 'recovery';
    case TgCheck = 'tg_check';
}
