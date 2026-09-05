<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Evidence;

/**
 * Applicability level of an evidence type for a proxy protocol
 * (plan §11.35 item 9).
 */
enum Applicability: string
{
    case Required = 'required';
    case Optional = 'optional';
    case NotApplicable = 'not_applicable';
}
