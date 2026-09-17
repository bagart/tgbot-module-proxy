<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

enum FeedSourceStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Error = 'error';
    case Deleted = 'deleted';
}
