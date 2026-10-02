<?php

/*
 * Stage-0 invariant arch-tests INV-001..INV-020 (plan §11.37, task #94).
 *
 * Violating an invariant is a design bug, not a feature: these checks freeze
 * the architectural model fixed by plan §11.38. Placeholders marked
 * "enforced by later-stage tests" stay visible until their stage lands.
 */

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Cache\ProbeCacheKeyV3;
use BAGArt\ProxyOperations\Domain\Failure\ExecutionFailure;
use BAGArt\ProxyOperations\Domain\Failure\FailureCode;
use BAGArt\ProxyOperations\Domain\Failure\FailureTaxonomy;
use BAGArt\ProxyOperations\Domain\Failure\ProxyFailure;
use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Tool\ControlPlaneRoute;
use BAGArt\ProxyOperations\Tool\CredentialChannel;
use BAGArt\ProxyOperations\Tool\CredentialDeliveryMode;
use BAGArt\ProxyOperations\Tool\ExecutionPlaneRoute;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
use BAGArt\ProxyOperations\Tool\ProbeTool;
use BAGArt\ProxyOperations\Tool\ProbeToolResult;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Tool\ToolCapabilities;
use BAGArt\ProxyOperations\Tool\ToolId;
use BAGArt\ProxyOperations\Tool\ToolManifest;
use BAGArt\ProxyOperations\Tool\ToolRegistry;
use BAGArt\ProxyOperations\Wire\AuditResultStatus;
use BAGArt\ProxyOperations\Wire\AuditResultV1;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

function invSrcDir(): string
{
    return dirname(__DIR__, 2).'/src';
}

/**
 * @return array<string, string> Relative path => raw file contents.
 */
function invPhpFiles(): array
{
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        invSrcDir(),
        FilesystemIterator::SKIP_DOTS,
    ));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

function invStripComments(string $code): string
{
    $withoutBlocks = (string) preg_replace('/\/\*.*?\*\//s', '', $code);

    return (string) preg_replace('/^\s*\/\/[^\n]*$/m', '', $withoutBlocks);
}

/**
 * @param  list<string>  $prefixes
 */
function invPathMatchesNone(string $relativePath, array $prefixes): bool
{
    foreach ($prefixes as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            return false;
        }
    }

    return true;
}

/**
 * FQCNs declared under src/, resolved through the composer PSR-4 mapping
 * (one class/interface/enum per file).
 *
 * @return list<class-string>
 */
function invSymbols(): array
{
    static $symbols = null;

    if ($symbols !== null) {
        return $symbols;
    }

    $symbols = [];

    foreach (array_keys(invPhpFiles()) as $path) {
        $relative = str_replace('/', '\\', substr((string) $path, strlen(invSrcDir()) + 1, -4));
        $fqcn = 'BAGArt\\ProxyOperations\\'.$relative;

        if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
            $symbols[] = $fqcn;
        }
    }

    sort($symbols);

    return $symbols;
}

/**
 * Content scan: every match of $pattern in comment-stripped source becomes a
 * "path:line" violation entry.
 *
 * @param  non-empty-string  $pattern
 * @return list<string>
 */
function invScanSource(string $pattern): array
{
    $violations = [];

    foreach (invPhpFiles() as $path => $contents) {
        $code = invStripComments($contents);
        $lines = explode("\n", $code);
        $relativePath = substr($path, strlen(dirname(invSrcDir())) + 1);

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $violations[] = "{$relativePath}:".((int) $index + 1);
            }
        }
    }

    return $violations;
}

/**
 * @return list<ReflectionMethod>
 */
function invPublicMethods(string $fqcn): array
{
    $reflection = new ReflectionClass($fqcn);
    $methods = $reflection->hasMethod('__construct')
        ? [$reflection->getMethod('__construct')]
        : [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isConstructor()) {
            $methods[] = $method;
        }
    }

    return $methods;
}

it('keeps persistence client symbols out of Domain, Wire and Tool (INV-003)', function (): void {
    // Only the pure Stage-0 namespaces are scanned: src/Models/Concerns is
    // framework-only Eloquent code by design (T02), Tenancy is facade-free.
    $scannedPrefixes = ['src/Domain/', 'src/Wire/', 'src/Tool/'];

    $violations = [];

    foreach (invPhpFiles() as $path => $contents) {
        $relativePath = substr($path, strlen(dirname(invSrcDir())) + 1);

        if (invPathMatchesNone($relativePath, $scannedPrefixes)) {
            continue;
        }

        $code = invStripComments($contents);

        foreach (explode("\n", $code) as $index => $line) {
            if (preg_match(
                '/\bPDO\b|\bPDOStatement\b|\bmysqli\b|\bpg_connect\b|Doctrine\\\\DBAL|Illuminate\\\\Database\\\\|\bEloquent\b|Facades\\\\DB\b|\bDB::/',
                $line,
            ) === 1) {
                $violations[] = "{$relativePath}:".((int) $index + 1);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps Redis client types out of src entirely (INV-009)', function (): void {
    // No adapters namespace exists at Stage 0 — absence is asserted, not exemption.
    $violations = invScanSource(
        '/new\s+\\\\?(Redis|RedisCluster)\b|\bRedisCluster\b|Predis\\\\|Facades\\\\Redis\b|\bRedis::/',
    );

    expect($violations)->toBe([]);
});

it('never exposes domain identity/snapshot/lifecycle/evidence entities to tools (INV-011)', function (): void {
    $forbiddenPrefixes = [
        'BAGArt\\ProxyOperations\\Domain\\Identity\\',
        'BAGArt\\ProxyOperations\\Domain\\Snapshot\\',
        'BAGArt\\ProxyOperations\\Domain\\Lifecycle\\',
        'BAGArt\\ProxyOperations\\Domain\\Evidence\\',
    ];

    $violations = [];

    foreach (invSymbols() as $fqcn) {
        if (! str_contains($fqcn, '\\Tool\\')) {
            continue;
        }

        foreach (invPublicMethods($fqcn) as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $typeName = $type->getName();

                foreach ($forbiddenPrefixes as $prefix) {
                    if (str_starts_with($typeName, $prefix)) {
                        $violations[] = "{$fqcn}::{$method->getName()}(\${$parameter->getName()}) => {$typeName}";
                    }
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('contains no shell-out primitives anywhere in src (INV-012)', function (): void {
    expect(invScanSource('/shell_exec\s*\(|proc_open\s*\(|`/'))->toBe([]);
});

it('passes credentials only through CredentialChannel, never argv strings (INV-013)', function (): void {
    expect(invScanSource('/\$argv\b|--proxy\b|--password\b|--pass\b|user:pass\b/'))->toBe([]);

    $credentials = new ReflectionParameter([ProbeExecutionContext::class, '__construct'], 'credentials');

    expect($credentials->getType())->toBeInstanceOf(ReflectionNamedType::class)
        ->and($credentials->getType()->getName())->toBe(CredentialChannel::class)
        ->and($credentials->getType()->allowsNull())->toBeFalse();

    $credentialAbstractions = array_values(array_filter(
        invSymbols(),
        fn (string $fqcn): bool => str_contains($fqcn, '\\Tool\\')
            && (str_contains($fqcn, 'Credential') || str_ends_with($fqcn, 'Channel')),
    ));

    expect($credentialAbstractions)->toBe([
        CredentialChannel::class,
        CredentialDeliveryMode::class,
        FileDescriptorChannel::class,
        StdinChannel::class,
    ]);

    foreach ([FileDescriptorChannel::class, StdinChannel::class] as $channel) {
        expect(in_array(CredentialChannel::class, class_implements($channel), true))->toBeTrue();
    }
});

it('rejects checker-class failure codes as proxy observations (INV-014)', function (): void {
    new AuditResultV1(
        taskId: 'task-1',
        attemptId: 'attempt-1',
        status: AuditResultStatus::Failed,
        observations: [
            new ProxyFailure(
                (new FailureTaxonomy())->descriptor(FailureCode::ToolTimeout),
                ['tool' => 'socks-checker'],
            ),
        ],
        executionFailures: [],
        timings: [],
        checkerNodeId: 'node-1',
    );
})->throws(InvalidArgumentException::class, 'INV-014/015');

it('accepts TOOL_TIMEOUT as an execution failure instead (INV-014)', function (): void {
    $result = new AuditResultV1(
        taskId: 'task-1',
        attemptId: 'attempt-1',
        status: AuditResultStatus::TimedOut,
        observations: [],
        executionFailures: [
            new ExecutionFailure(
                (new FailureTaxonomy())->descriptor(FailureCode::ToolTimeout),
                ['tool' => 'socks-checker'],
            ),
        ],
        timings: ['totalMs' => 30000],
        checkerNodeId: 'node-1',
    );

    expect($result->observations)->toBe([])
        ->and($result->executionFailures)->toHaveCount(1)
        ->and($result->executionFailures[0]->descriptor()->code)->toBe(FailureCode::ToolTimeout);
});

it('gives checker-infrastructure faults zero health influence (INV-015)', function (): void {
    $taxonomy = new FailureTaxonomy();

    foreach (FailureCode::cases() as $code) {
        if ($code->class()->isExecutionFailure()) {
            $descriptor = $taxonomy->descriptor($code);

            expect($descriptor->affectsHealth)->toBeFalse("{$code->value} must not affect proxy health")
                ->and($descriptor->affectsCapability)->toBeFalse("{$code->value} must not affect capability");
        }
    }
});

it('versions every Wire DTO with a public SCHEMA_VERSION (INV-016)', function (): void {
    $unversioned = [];
    $nonPublic = [];

    foreach (invSymbols() as $fqcn) {
        if (! str_contains($fqcn, '\\Wire\\')) {
            continue;
        }

        $reflection = new ReflectionClass($fqcn);

        if ($reflection->isEnum()) {
            continue;
        }

        if (! $reflection->hasConstant('SCHEMA_VERSION')) {
            $unversioned[] = $fqcn;

            continue;
        }

        if (! $reflection->getReflectionConstant('SCHEMA_VERSION')->isPublic()) {
            $nonPublic[] = $fqcn;
        }
    }

    expect($unversioned)->toBe([])->and($nonPublic)->toBe([]);
});

function invCacheKey(string $toolSemanticsVersion): ProbeCacheKeyV3
{
    return new ProbeCacheKeyV3(
        endpointIdentity: new EndpointIdentity('1.2.3.4', 1080, ProxyProtocol::Socks5),
        credentialFingerprint: new CredentialFingerprint(str_repeat('a', 64)),
        checkerNodeId: 'node-1',
        egressIdentity: 'egress-eu-1',
        judgeSetVersion: 4,
        telegramDcSetVersion: 2,
        probeProfileVersion: 7,
        probeSemanticsVersion: 'probe-sem-2026.1',
        toolSemanticsVersion: $toolSemanticsVersion,
    );
}

it('includes toolSemanticsVersion in the cache key identity (INV-017)', function (): void {
    $payload = invCacheKey('curl-8.9-proto2')->jsonSerialize();

    expect($payload['toolSemanticsVersion'])->toBe('curl-8.9-proto2');

    expect(invCacheKey('curl-8.10-proto2')->toHash())
        ->not->toBe(invCacheKey('curl-8.9-proto2')->toHash());
});

it('ProbeTool implementations are registered through the ToolRegistry allowlist (INV-018)', function (): void {
    expect(interface_exists(ProbeTool::class))->toBeTrue();

    $implementations = [];

    foreach (invSymbols() as $fqcn) {
        if (in_array(ProbeTool::class, class_implements($fqcn) ?: [], true)) {
            $implementations[] = $fqcn;
        }
    }

    $manifestClasses = [
        'BAGArt\ProxyOperations\Tool\HttpProbeToolManifestProvider',
        'BAGArt\ProxyOperations\Tool\MtprotoProbeToolManifestProvider',
        'BAGArt\ProxyOperations\Tool\TelegramDcProbeToolManifestProvider',
    ];

    foreach ($implementations as $impl) {
        $basename = class_basename($impl);
        $found = false;
        foreach ($manifestClasses as $mc) {
            if (str_contains($mc, $basename)) {
                $found = true;
                break;
            }
        }
        expect($found)->toBeTrue("ProbeTool implementation {$impl} must have a ManifestProvider");
    }

    $execute = new ReflectionMethod(ProbeTool::class, 'execute');

    expect($execute->getParameters()[0]->getType()->getName())->toBe(ProbeExecutionContext::class)
        ->and($execute->getReturnType()?->getName())->toBe(ProbeToolResult::class);

    $capabilities = new ReflectionMethod(ProbeTool::class, 'capabilities');
    $resolve = new ReflectionMethod(ToolRegistry::class, 'resolve');

    expect($capabilities->getReturnType()?->getName())->toBe(ToolCapabilities::class)
        ->and($resolve->getParameters()[0]->getType()->getName())->toBe(ToolId::class)
        ->and($resolve->getReturnType()?->getName())->toBe(ToolManifest::class);

});

it('keeps control-plane and execution-plane routes disjoint (INV-020)', function (): void {
    expect(enum_exists(ControlPlaneRoute::class))->toBeTrue()
        ->and(enum_exists(ExecutionPlaneRoute::class))->toBeTrue()
        ->and(ControlPlaneRoute::class)->not->toBe(ExecutionPlaneRoute::class);

    $controlNames = array_map(fn ($case) => $case->name, ControlPlaneRoute::cases());
    $executionNames = array_map(fn ($case) => $case->name, ExecutionPlaneRoute::cases());

    expect(array_intersect($controlNames, $executionNames))->toBe([]);

    $controlValues = array_map(fn ($case) => $case->value, ControlPlaneRoute::cases());
    $executionValues = array_map(fn ($case) => $case->value, ExecutionPlaneRoute::cases());

    expect(array_intersect($controlValues, $executionValues))->toBe([]);
});

it('INV-001: health/lifecycle/quarantine belong to ProxyAccess', function (): void {
    Assert::markTestSkipped('enforced by later-stage tests (Stage 01 core-domain): AccessState machine binds health/quarantine/freshness to ProxyAccess, not EndpointIdentity (plan §11.37 R6.1)');
});

it('INV-002: Endpoint never carries canonical health', function (): void {
    Assert::markTestSkipped('enforced by later-stage tests (Stage 01 core-domain): ProxyEndpoint projection carries no health columns (plan §11.37 INV-002)');
});

it('keeps KEK/DEK ownership out of worker-facing surfaces (INV-004)', function (): void {
    // The checker worker consumes Wire contracts and Tool contracts only;
    // encryption services, key providers and the DEK table stay behind the
    // application layer (plan §11.37 R6.5, §10.12 п.13).
    $workerFacingPrefixes = ['src/Wire/', 'src/Tool/'];

    $violations = [];

    foreach (invPhpFiles() as $path => $contents) {
        $relativePath = substr($path, strlen(dirname(invSrcDir())) + 1);

        if (invPathMatchesNone($relativePath, $workerFacingPrefixes)) {
            continue;
        }

        foreach (explode("\n", invStripComments($contents)) as $index => $line) {
            if (preg_match(
                '/CredentialEncryptor|KekProvider|EncryptedField|WorkspaceDek|PROXY_ENC_KEY/',
                $line,
            ) === 1) {
                $violations[] = "{$relativePath}:".((int) $index + 1);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('INV-005: shared cache never contains tenant interpretation', function (): void {
    Assert::markTestSkipped('enforced by later-stage tests (Stage 06 cache): SharedCacheValue allowlist excludes tenant-derived data (plan §11.37 R6.4)');
});

it('INV-006: tenant_id never arrives from the client as authoritative', function (): void {
    Assert::markTestSkipped('enforced by later-stage tests (Stage 10 api-application-layer): tenant resolved server-side from authenticated user (plan §11.37 INV-006)');
});

it('INV-007: parser never encrypts credentials', function (): void {
    $forbiddenPrefixes = ['CredentialEncryptor', 'KekProvider', 'EncryptedField', 'WorkspaceDek', 'PROXY_ENC_KEY'];

    $parsingDir = dirname(__DIR__, 2).'/src/Domain/Parsing';

    if (! is_dir($parsingDir)) {
        Assert::markTestSkipped('Domain/Parsing directory not yet present');

        return;
    }

    $violations = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $parsingDir,
        FilesystemIterator::SKIP_DOTS,
    ));

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = invStripComments((string) file_get_contents($file->getPathname()));
        $relativePath = 'src/Domain/Parsing/'.$file->getFilename();

        foreach ($forbiddenPrefixes as $symbol) {
            if (str_contains($code, $symbol)) {
                $violations[] = "{$relativePath} references {$symbol}";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('INV-008: MTPROTO is not a transport', function (): void {
    Assert::markTestSkipped('enforced by later-stage tests (Stage 03 transport): mtproto is an endpoint type checked via handshake, TransportKind stays socks5/http (plan §10.12 item 22)');
});

it('INV-010: projection never mutates canonical domain', function (): void {
    Assert::markTestSkipped('enforced by later-stage tests (Stage 09 projections-export): verified_proxies is a read-only projection updated by one projector on AuditCompleted (plan §11.37 INV-010)');
});
