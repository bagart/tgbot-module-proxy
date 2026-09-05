<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

/**
 * Interpretation of one raw ProbeToolResult by the ProbeOutcomeClassifier
 * (plan §§11.9, 11.39 пп.13–14). Structural invariant INV-014/015: a result
 * classified as ExecutionFailure never becomes a proxy observation and vice
 * versa.
 */
enum ProbeOutcomeClassification: string
{
    case Success = 'success';

    case ProxyFailure = 'proxy_failure';

    case ExecutionFailure = 'execution_failure';
}
