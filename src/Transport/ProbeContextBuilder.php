<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
use BAGArt\ProxyOperations\Tool\ProbeSpec;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

/**
 * Converts AuditTaskV1 + ProbeExecutionSpecV1 → ProbeExecutionContext.
 * This is the bridge from wire contracts to Tool-layer execution input.
 *
 * Domain entities (AccessIdentity, TenantId) are NOT propagated — only
 * host, port, credential channel, probe spec, timeout and output limit
 * (INV-011).
 */
final readonly class ProbeContextBuilder
{
    public function fromAuditTask(
        AuditTaskV1 $task,
        ProbeExecutionSpecV1 $probe,
    ): ProbeExecutionContext {
        $endpoint = $task->accessRef->endpoint;

        return new ProbeExecutionContext(
            host: $endpoint->host,
            port: $endpoint->port,
            credentials: $this->resolveCredentialChannel($task),
            spec: new ProbeSpec(
                probeType: $probe->probeType,
                target: $probe->target,
            ),
            timeoutMs: $probe->timeoutMs,
            maxOutputBytes: $probe->maxOutputBytes,
        );
    }

    private function resolveCredentialChannel(AuditTaskV1 $task): StdinChannel|FileDescriptorChannel
    {
        if ($task->sealedCredential !== null) {
            return new StdinChannel;
        }

        return new FileDescriptorChannel(fileDescriptor: 3);
    }
}
