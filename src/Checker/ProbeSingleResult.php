<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Checker;

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;

/**
 * Raw outcome of one probe execution (plan §11.39 пп.5–6): the probe type,
 * the judge it ran against (when judge-dependent), and the untouched
 * ProbeToolResult. No interpretation happens here — that is the
 * ExecutionResultNormalizer's job (INV-014/015).
 */
final readonly class ProbeSingleResult
{
    public function __construct(
        public readonly ProbeType $probeType,
        public readonly ?string $judgeId,
        public readonly ProbeToolResult $toolResult,
    ) {
    }
}
