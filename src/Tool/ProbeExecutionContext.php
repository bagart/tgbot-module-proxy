<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use InvalidArgumentException;

/**
 * The only input a ProbeTool implementation receives (plan §11.39 пп.5–6):
 * endpoint coordinates, the credential delivery plan, the probe spec and its
 * limits. No domain entities on this surface — no AccessIdentity, Tenant or
 * AuditJob ever reaches the execution boundary (INV-011), and no credential
 * string exists here at all (INV-013).
 */
final readonly class ProbeExecutionContext
{
    /**
     * @param  non-empty-string  $host
     * @param  int<1, 65535>  $port
     * @param  positive-int  $timeoutMs  Per-probe wall-clock limit enforced by the worker.
     * @param  positive-int  $maxOutputBytes  Output cap enforced by the Resource Governor (INV-019).
     */
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly CredentialChannel $credentials,
        public readonly ProbeSpec $spec,
        public readonly int $timeoutMs,
        public readonly int $maxOutputBytes,
    ) {
        if ($this->host === '') {
            throw new InvalidArgumentException('Endpoint host must not be empty.');
        }

        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException('Endpoint port must be within 1..65535.');
        }

        if ($this->timeoutMs < 1) {
            throw new InvalidArgumentException('Probe timeoutMs must be >= 1.');
        }

        if ($this->maxOutputBytes < 1) {
            throw new InvalidArgumentException('Probe maxOutputBytes must be >= 1.');
        }
    }
}
