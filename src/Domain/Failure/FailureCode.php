<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Failure;

/**
 * Single failure vocabulary returned by any probe (plan §11.16).
 * The domain only emits codes; locale-specific wording is applied in presentation.
 */
enum FailureCode: string
{
    case TcpTimeout = 'TCP_TIMEOUT';
    case TcpRefused = 'TCP_REFUSED';
    case AuthFailure = 'AUTH_FAILURE';
    case TlsFailure = 'TLS_FAILURE';
    case MtprotoHandshakeFailed = 'MTPROTO_HANDSHAKE_FAILED';
    case Target4xx = 'TARGET_4XX';
    case Target5xx = 'TARGET_5XX';
    case JudgeUnavailable = 'JUDGE_UNAVAILABLE';
    case JudgeInconsistent = 'JUDGE_INCONSISTENT';
    case ToolTimeout = 'TOOL_TIMEOUT';
    case ToolCrash = 'TOOL_CRASH';
    case ToolProtocolError = 'TOOL_PROTOCOL_ERROR';
    case ToolOom = 'TOOL_OOM';
    case ToolExitFailure = 'TOOL_EXIT_FAILURE';
    case ToolOutputInvalid = 'TOOL_OUTPUT_INVALID';
    case ToolUnavailable = 'TOOL_UNAVAILABLE';
    case RedisUnavailable = 'REDIS_UNAVAILABLE';
    case StorageUnavailable = 'STORAGE_UNAVAILABLE';
    case SsrfBlocked = 'SSRF_BLOCKED';
    case UnsupportedProtocol = 'UNSUPPORTED_PROTOCOL';

    public function class(): FailureClass
    {
        return match ($this) {
            self::TcpTimeout,
            self::TcpRefused,
            self::AuthFailure,
            self::TlsFailure,
            self::MtprotoHandshakeFailed => FailureClass::Proxy,
            self::Target4xx,
            self::Target5xx => FailureClass::Target,
            self::JudgeUnavailable,
            self::JudgeInconsistent => FailureClass::Judge,
            self::ToolTimeout,
            self::ToolCrash,
            self::ToolProtocolError,
            self::ToolOom,
            self::ToolExitFailure,
            self::ToolOutputInvalid,
            self::ToolUnavailable => FailureClass::Checker,
            self::RedisUnavailable,
            self::StorageUnavailable => FailureClass::Platform,
            self::SsrfBlocked,
            self::UnsupportedProtocol => FailureClass::Policy,
        };
    }
}
