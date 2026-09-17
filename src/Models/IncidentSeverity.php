<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

enum IncidentSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';
    case Emergency = 'emergency';
}
