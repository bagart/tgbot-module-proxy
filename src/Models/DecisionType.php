<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

enum DecisionType: string
{
    case Import = 'import';
    case Audit = 'audit';
    case Health = 'health';
    case Lifecycle = 'lifecycle';
    case Pool = 'pool';
    case Feed = 'feed';
}
