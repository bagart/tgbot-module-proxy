<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\ProbeExecutor;
use BAGArt\ProxyOperations\Checker\ToolTimeoutFactory;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Checker\ExecutionResultNormalizer;
use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
use BAGArt\ProxyOperations\Tool\ProbeTool;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Tool\ToolCapabilities;
use BAGArt\ProxyOperations\Tool\ToolId;
use BAGArt\ProxyOperations\Tool\ToolLimits;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use BAGArt\ProxyOperations\Tool\ToolSecurity;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditTaskV1;
use BAGArt\ProxyOperations\Wire\ProbeExecutionSpecV1;

/**
 * ProbeTool that always succeeds but reports a proxy-side failure via the
 * self-describing failureCode observation key (T91/T20 tool contract).
 */
final class T22ProxyFailureTool implements ProbeTool
{
    public function capabilities(): ToolCapabilities
    {
        return new ToolCapabilities(
            probeTypes: [ProbeType::LatencySeries],
            protocols: ['socks5'],
            inputSchemaVersion: 1,
            outputSchemaVersion: 1,
        );
    }

    public function execute(ProbeExecutionContext $context): ProbeToolResult
    {
        return ProbeToolResult::ok(
            observations: ['failureCode' => FailureCode::TcpRefused->value],
            timingsMs: ['connect' => 3.0],
        );
    }
}

/**
 * ProbeTool whose execution crashes — a checker-side (execution) fault.
 */
final class T22CrashingTool implements ProbeTool
{
    public function capabilities(): ToolCapabilities
    {
        return new ToolCapabilities(
            probeTypes: [ProbeType::DnsResolution],
            protocols: ['socks5'],
            inputSchemaVersion: 1,
            outputSchemaVersion: 1,
        );
    }

    public function execute(ProbeExecutionContext $context): ProbeToolResult
    {
        throw new RuntimeException('dns tool exploded');
    }
}

function t22MixedRegistry(): ToolRegistry
{
    return new ToolRegistry([
        'latency-tool' => new ToolManifest(
            name: new ToolId('latency-tool'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: [ProbeType::LatencySeries],
                protocols: ['socks5'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 60, maxOutputBytes: 1024 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        ),
        'dns-tool' => new ToolManifest(
            name: new ToolId('dns-tool'),
            version: '1.0.0',
            apiVersion: 1,
            capabilities: new ToolCapabilities(
                probeTypes: [ProbeType::DnsResolution],
                protocols: ['socks5'],
                inputSchemaVersion: 1,
                outputSchemaVersion: 1,
            ),
            limits: new ToolLimits(maxExecutionTimeSeconds: 60, maxOutputBytes: 1024 * 1024),
            security: new ToolSecurity(network: 'outbound-only', filesystem: 'readonly', privileges: 'none'),
        ),
    ]);
}

it('runs the mixed-outcome pipeline: proxy failure observed, tool crash isolated', function (): void {
    $proxyFailureTool = new T22ProxyFailureTool;
    $crashingTool = new T22CrashingTool;

    app()->singleton(ProbeExecutor::class, static function ($app) use ($proxyFailureTool, $crashingTool) {
        return new ProbeExecutor(
            tools: [
                'latency-tool' => $proxyFailureTool,
                'dns-tool' => $crashingTool,
            ],
            judgeProvider: $app->make(BAGArt\ProxyOperations\Checker\JudgeProvider::class),
            toolRegistry: t22MixedRegistry(),
            governor: $app->make(BAGArt\ProxyOperations\Transport\ResourceGovernor::class),
            timeoutFactory: $app->make(ToolTimeoutFactory::class),
        );
    });

    $task = new AuditTaskV1(
        job: new BAGArt\ProxyOperations\Wire\JobRef(jobId: 'job-2', attemptId: 'att-2', taskId: 'task-2'),
        tenantId: 'tenant-1',
        accessRef: new BAGArt\ProxyOperations\Domain\Identity\AccessIdentity(
            endpoint: new BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity(
                host: '203.0.113.10',
                port: 1080,
                protocol: BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol::Socks5,
            ),
            credential: new BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint('fp:beef'),
        ),
        sealedCredential: null,
        credentialReference: new BAGArt\ProxyOperations\Wire\CredentialReference(handle: 'handle-2'),
        probes: [
            new ProbeExecutionSpecV1(
                probeType: ProbeType::LatencySeries,
                profile: BAGArt\ProxyOperations\Domain\Probe\ProbeProfile::Standard,
                target: '8.8.8.8:53',
                timeoutMs: 15000,
                maxOutputBytes: 65536,
            ),
            new ProbeExecutionSpecV1(
                probeType: ProbeType::DnsResolution,
                profile: BAGArt\ProxyOperations\Domain\Probe\ProbeProfile::Standard,
                target: 'example.com',
                timeoutMs: 15000,
                maxOutputBytes: 65536,
            ),
        ],
        policySnapshotVersion: 1,
        deadline: '2026-08-30T00:10:00Z',
        maxAttempts: 3,
    );

    $outcome = app(ProbeExecutor::class)->execute($task);
    $result = app(ExecutionResultNormalizer::class)->normalize($outcome, 'checker-2');

    // Partial success is still Completed: proxy failures are expected audit
    // outcomes, and a proxy-side failure was observed alongside the crash.
    expect($result->status)->toBe(AuditResultStatus::Completed)
        // Proxy-side failure lands in observations.
        ->and($result->observations)->toHaveCount(1)
        ->and($result->observations[0]->descriptor->code)->toBe(FailureCode::TcpRefused)
        // Checker-side crash lands in executionFailures, never observations.
        ->and($result->executionFailures)->toHaveCount(1)
        ->and($result->executionFailures[0]->descriptor->code)->toBe(FailureCode::ToolCrash)
        ->and($result->executionFailures[0]->context['exception_class'])->toBe(RuntimeException::class)
        // Counts match the raw outcome.
        ->and($outcome->results)->toHaveCount(2)
        ->and($outcome->successCount)->toBe(1)
        ->and($outcome->failureCount)->toBe(0)
        ->and($outcome->executionFailureCount)->toBe(1)
        ->and($result->checkerNodeId)->toBe('checker-2');
});
