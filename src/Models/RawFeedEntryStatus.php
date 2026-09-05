<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

/**
 * Lifecycle status of a raw feed entry from import staging through parse
 * completion (plan §§11.21, 11.28).
 */
enum RawFeedEntryStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Skipped = 'skipped';
    case Error = 'error';
}
