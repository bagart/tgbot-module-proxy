<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

enum ImportSource: string
{
    case Paste = 'paste';
    case File = 'file';
    case Feed = 'feed';
}
