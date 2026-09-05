<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

/**
 * Dimension-specific evidence types (plan §11.35 item 9, R6.3).
 *
 * Evidence is not a linear ladder: each type proves exactly one capability
 * dimension and has its own applicability per protocol (EvidenceApplicability).
 */
enum EvidenceType: string
{
    case Tcp = 'tcp';
    case Http = 'http';
    case Tls = 'tls';
    case Dns = 'dns';
    case Udp = 'udp';
    case Judge = 'judge';
    case Telegram = 'telegram';
    case Bandwidth = 'bandwidth';
}
