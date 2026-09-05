<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\CredentialChannel;
use BAGArt\ProxyOperations\Tool\ProbeExecutionContext;
use BAGArt\ProxyOperations\Tool\ProbeSpec;

/**
 * Plan §11.39 п.5 (INV-011): the execution boundary must not leak domain
 * entities — no AccessIdentity/Tenant/AuditJob or Identity/Snapshot/Lifecycle
 * model types may appear anywhere on ProbeExecutionContext's signature surface.
 */
function probeExecutionContextSignatureTypes(): array
{
    $reflection = new ReflectionClass(ProbeExecutionContext::class);

    $typesFrom = function (ReflectionNamedType|ReflectionUnionType|ReflectionIntersectionType|null $type): array {
        if ($type === null) {
            return [];
        }

        if ($type instanceof ReflectionNamedType) {
            return [$type->getName()];
        }

        return array_map(
            static fn (ReflectionNamedType|ReflectionIntersectionType $nested): string => $nested instanceof ReflectionNamedType
                ? $nested->getName()
                : implode('&', array_map(strval(...), $nested->getTypes())),
            $type->getTypes(),
        );
    };

    $signatures = [$typesFrom($reflection->getConstructor()?->getReturnType())];
    foreach ($reflection->getConstructor()->getParameters() as $parameter) {
        $signatures[] = $typesFrom($parameter->getType());
    }
    foreach ($reflection->getMethods() as $method) {
        $signatures[] = $typesFrom($method->getReturnType());
        foreach ($method->getParameters() as $parameter) {
            $signatures[] = $typesFrom($parameter->getType());
        }
    }

    return array_merge(...$signatures);
}

it('exposes only scalars, CredentialChannel and ProbeSpec types', function (): void {
    expect(probeExecutionContextSignatureTypes())
        ->each->toBeIn(['string', 'int', CredentialChannel::class, ProbeSpec::class]);
});

it('contains no domain entity types in constructor or method signatures', function (): void {
    $forbiddenNamespaces = [
        'BAGArt\ProxyOperations\Domain\Identity',
        'BAGArt\ProxyOperations\Domain\Snapshot',
        'BAGArt\ProxyOperations\Domain\Lifecycle',
        'BAGArt\ProxyOperations\Domain\Evidence',
    ];
    $forbiddenNames = ['AccessIdentity', 'Tenant', 'AuditJob'];

    foreach (probeExecutionContextSignatureTypes() as $type) {
        foreach ($forbiddenNamespaces as $namespace) {
            expect(str_starts_with($type.'\\', $namespace.'\\'))->toBeFalse();
        }

        expect(in_array(substr($type, (int) strrpos($type, '\\') + 1), $forbiddenNames, true))->toBeFalse();
    }
});
