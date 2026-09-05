<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Policy\DenylistResolvedTargetChecker;
use BAGArt\ProxyOperations\Domain\Policy\IpDenylist;
use BAGArt\ProxyOperations\Domain\Policy\JudgeConnectPolicy;
use BAGArt\ProxyOperations\Domain\Policy\ProxyEndpointConnectPolicy;
use BAGArt\ProxyOperations\Domain\Policy\ResolvedTargetChecker;
use BAGArt\ProxyOperations\Domain\Policy\TargetFetchPolicy;

const DENYLIST_VECTORS = ['127.0.0.1', '10.0.0.7', '::1', 'fd00::1', 'fe80::1', '169.254.169.254'];

it('applies the denylist vectors unless private endpoints are explicitly allowed', function (string $ip): void {
    $strict = new ProxyEndpointConnectPolicy(denylist: IpDenylist::ssrfDefault(), allowPrivateEndpoints: false, resolveThenConnect: true);
    $permissive = ProxyEndpointConnectPolicy::forInventoryAudit();

    expect($strict->allowsEndpointIp($ip))->toBeFalse()
        ->and($permissive->allowsEndpointIp($ip))->toBeTrue();
})->with(DENYLIST_VECTORS);

it('lets public endpoints through both proxy endpoint policy variants', function (): void {
    $strict = new ProxyEndpointConnectPolicy(denylist: IpDenylist::ssrfDefault(), allowPrivateEndpoints: false, resolveThenConnect: true);

    expect($strict->allowsEndpointIp('93.184.216.34'))->toBeTrue()
        ->and(ProxyEndpointConnectPolicy::forInventoryAudit()->allowsEndpointIp('93.184.216.34'))->toBeTrue();
});

it('fails closed on malformed endpoint IPs', function (): void {
    expect(ProxyEndpointConnectPolicy::forInventoryAudit()->allowsEndpointIp('localhost'))->toBeFalse()
        ->and(TargetFetchPolicy::hardened()->allowsTargetIp('localhost'))->toBeFalse();
});

it('allows exactly the fixed judges and nothing else', function (string $candidate, bool $expected): void {
    $policy = new JudgeConnectPolicy(judgeUrls: [
        'https://judge-1.example.org/echo',
        'https://judge-2.example.org:8443/echo',
    ]);

    expect($policy->allowsJudgeUrl($candidate))->toBe($expected);
})->with([
    'allowlisted judge' => ['https://judge-1.example.org/echo', true],
    'allowlisted judge, host case and trailing whitespace only' => [' HTTPS://JUDGE-1.EXAMPLE.ORG/echo ', true],
    'second allowlisted judge with port' => ['https://judge-2.example.org:8443/echo', true],
    'unknown host' => ['https://evil.example.org/echo', false],
    'same host, different path' => ['https://judge-1.example.org/other', false],
    'same host, different port' => ['https://judge-1.example.org:9/echo', false],
    'downgraded scheme' => ['http://judge-1.example.org/echo', false],
    'private destination attempt' => ['https://127.0.0.1/echo', false],
    'malformed url' => ['not a url', false],
]);

it('rejects all denylist vectors for target fetches', function (string $ip): void {
    expect(TargetFetchPolicy::hardened()->allowsTargetIp($ip))->toBeFalse();
})->with(DENYLIST_VECTORS);

it('allows public targets and keeps redirects disabled by default', function (): void {
    $policy = TargetFetchPolicy::hardened();

    expect($policy->allowsTargetIp('93.184.216.34'))->toBeTrue()
        ->and($policy->followRedirects)->toBeFalse()
        ->and($policy->markerSecret)->not->toBe('');
});

it('enforces the resolve-then-connect gate fail-closed via the checker contract', function (): void {
    $checker = new DenylistResolvedTargetChecker(IpDenylist::ssrfDefault());

    expect($checker)->toBeInstanceOf(ResolvedTargetChecker::class)
        ->and($checker->isConnectionAllowed('example.org', []))->toBeFalse()
        ->and($checker->isConnectionAllowed('example.org', ['93.184.216.34']))->toBeTrue()
        ->and($checker->isConnectionAllowed('example.org', ['93.184.216.34', '169.254.169.254']))->toBeFalse()
        ->and($checker->isConnectionAllowed('example.org', ['garbage']))->toBeFalse();
});
