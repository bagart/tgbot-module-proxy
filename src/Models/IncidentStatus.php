<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

enum IncidentStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';
}
