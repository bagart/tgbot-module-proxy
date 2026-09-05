<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

/**
 * Where an inventory proxy came from (plan §11.21, T06): pasted lists,
 * uploaded files, recurring feeds or manual entry.
 */
enum SourceKind: string
{
    case Paste = 'paste';
    case File = 'file';
    case Feed = 'feed';
    case Manual = 'manual';
}
