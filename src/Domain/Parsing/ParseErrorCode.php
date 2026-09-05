<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Parsing;

/**
 * Structured error codes for proxy list parsing failures.
 *
 * Every rejected line maps to exactly one code — no generic "invalid" bucket.
 */
enum ParseErrorCode: string
{
    case EmptyLine = 'empty_line';
    case CommentLine = 'comment_line';
    case NonVpnRejected = 'non_vpn_rejected';
    case UnsupportedScheme = 'unsupported_scheme';
    case InvalidHost = 'invalid_host';
    case InvalidPort = 'invalid_port';
    case InvalidPortRange = 'invalid_port_range';
    case MissingPort = 'missing_port';
    case InvalidFormat = 'invalid_format';
    case InvalidMtprotoSecret = 'invalid_mtproto_secret';
    case CidrExpansionFailed = 'cidr_expansion_failed';
}
