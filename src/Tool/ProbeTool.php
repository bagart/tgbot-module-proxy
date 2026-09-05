<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

/**
 * ProbeTool contract (plan §11.39 п.8): the domain sees this interface, never
 * proc_open/shell_exec/curl (INV-018). Implementations (Curl/Socks/Dns/
 * Mtproto/Tcp) hide CLI/binary/library details behind it, so binaries stay
 * replaceable without touching the domain model (INV-011).
 */
interface ProbeTool
{
    public function capabilities(): ToolCapabilities;

    public function execute(ProbeExecutionContext $context): ProbeToolResult;
}
