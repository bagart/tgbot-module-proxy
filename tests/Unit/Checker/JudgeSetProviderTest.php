<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Checker\JudgeBudgetConfig;
use BAGArt\ProxyOperations\Checker\JudgeBudgetTracker;
use BAGArt\ProxyOperations\Checker\JudgeSelectionStrategy;
use BAGArt\ProxyOperations\Checker\JudgeSetProvider;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeDescriptor;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeTrustTier;

function t19JudgeBudgetConfig(int $rateLimit = 60): JudgeBudgetConfig
{
    return new JudgeBudgetConfig(rateLimitPerMinute: $rateLimit, windowSeconds: 60);
}

function t19JudgeBudgetTracker(int $rateLimit = 60): JudgeBudgetTracker
{
    return new JudgeBudgetTracker(t19JudgeBudgetConfig($rateLimit));
}

function t19MakeJudge(string $id, array $capabilities, JudgeTrustTier $tier = JudgeTrustTier::PublicHttps, int $rateLimit = 60): JudgeDescriptor
{
    return new JudgeDescriptor(
        id: $id,
        url: "https://judge-{$id}.example.com",
        region: 'us-east',
        protocol: 'https',
        capabilities: $capabilities,
        rateLimitPerMinute: $rateLimit,
        trustTier: $tier,
    );
}

function t19JudgeSnapshot(array $judges): JudgeSetSnapshot
{
    return new JudgeSetSnapshot(
        setId: 'snap-1',
        version: 1,
        judges: $judges,
        frozenAt: '2026-08-27T00:00:00Z',
    );
}

it('filters judges by probe-type capability match', function (): void {
    $judge1 = t19MakeJudge('j1', [ProbeType::HttpLiveness->value]);
    $judge2 = t19MakeJudge('j2', [ProbeType::DnsResolution->value]);
    $judge3 = t19MakeJudge('j3', [ProbeType::HttpLiveness->value, ProbeType::DnsResolution->value]);

    $snapshot = t19JudgeSnapshot([$judge1, $judge2, $judge3]);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::All);

    $result = $provider->select($snapshot, ProbeType::HttpLiveness, 10);

    expect($result)->toHaveCount(2)
        ->and(array_column($result, 'id'))->toBe(['j1', 'j3']);
});

it('returns all matching judges with All strategy', function (): void {
    $judges = array_map(
        static fn (int $i): JudgeDescriptor => t19MakeJudge("j{$i}", [ProbeType::HttpLiveness->value]),
        range(1, 5),
    );

    $snapshot = t19JudgeSnapshot($judges);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::All);

    $result = $provider->select($snapshot, ProbeType::HttpLiveness, 10);

    expect($result)->toHaveCount(5);
});

it('rotates across calls with RoundRobin strategy', function (): void {
    $j1 = t19MakeJudge('j1', [ProbeType::HttpLiveness->value], JudgeTrustTier::SelfHosted);
    $j2 = t19MakeJudge('j2', [ProbeType::HttpLiveness->value]);
    $j3 = t19MakeJudge('j3', [ProbeType::HttpLiveness->value]);

    $snapshot = t19JudgeSnapshot([$j1, $j2, $j3]);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::RoundRobin);

    $result1 = $provider->select($snapshot, ProbeType::HttpLiveness, 1);
    $result2 = $provider->select($snapshot, ProbeType::HttpLiveness, 1);
    $result3 = $provider->select($snapshot, ProbeType::HttpLiveness, 1);

    expect([$result1[0]->id, $result2[0]->id, $result3[0]->id])->toBe(['j1', 'j2', 'j3']);
});

it('returns a shuffled subset with Random strategy', function (): void {
    $judges = array_map(
        static fn (int $i): JudgeDescriptor => t19MakeJudge("j{$i}", [ProbeType::HttpLiveness->value]),
        range(1, 10),
    );

    $snapshot = t19JudgeSnapshot($judges);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::Random);

    $result = $provider->select($snapshot, ProbeType::HttpLiveness, 3);

    expect($result)->toHaveCount(3);
});

it('returns empty list when snapshot has no matching judges', function (): void {
    $judge = t19MakeJudge('j1', [ProbeType::DnsResolution->value]);
    $snapshot = t19JudgeSnapshot([$judge]);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::All);

    $result = $provider->select($snapshot, ProbeType::HttpLiveness, 5);

    expect($result)->toBeEmpty();
});

it('skips rate-limited judge when budget exhausted', function (): void {
    $judge = t19MakeJudge('j1', [ProbeType::HttpLiveness->value], rateLimit: 2);

    $snapshot = t19JudgeSnapshot([$judge]);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(2), JudgeSelectionStrategy::All);

    $provider->select($snapshot, ProbeType::HttpLiveness, 1);
    $provider->select($snapshot, ProbeType::HttpLiveness, 1);
    $result = $provider->select($snapshot, ProbeType::HttpLiveness, 1);

    expect($result)->toBeEmpty();
});

it('prefers higher trust tier judges when count < available', function (): void {
    $public1 = t19MakeJudge('pub1', [ProbeType::HttpLiveness->value], JudgeTrustTier::PublicHttps);
    $selfHosted = t19MakeJudge('self1', [ProbeType::HttpLiveness->value], JudgeTrustTier::SelfHosted);
    $public2 = t19MakeJudge('pub2', [ProbeType::HttpLiveness->value], JudgeTrustTier::PublicHttps);

    $snapshot = t19JudgeSnapshot([$public1, $selfHosted, $public2]);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::All);

    $result = $provider->select($snapshot, ProbeType::HttpLiveness, 1);

    expect($result)->toHaveCount(1)
        ->and($result[0]->id)->toBe('self1');
});

it('returns empty list when snapshot is empty', function (): void {
    $snapshot = t19JudgeSnapshot([]);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::All);

    $result = $provider->select($snapshot, ProbeType::HttpLiveness, 5);

    expect($result)->toBeEmpty();
});

it('round-robin cycles back to the start', function (): void {
    $j1 = t19MakeJudge('j1', [ProbeType::HttpLiveness->value]);
    $j2 = t19MakeJudge('j2', [ProbeType::HttpLiveness->value]);

    $snapshot = t19JudgeSnapshot([$j1, $j2]);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::RoundRobin);

    $provider->select($snapshot, ProbeType::HttpLiveness, 1);
    $provider->select($snapshot, ProbeType::HttpLiveness, 1);
    $result3 = $provider->select($snapshot, ProbeType::HttpLiveness, 1);

    expect($result3[0]->id)->toBe('j1');
});

it('respects count limit with All strategy', function (): void {
    $judges = array_map(
        static fn (int $i): JudgeDescriptor => t19MakeJudge("j{$i}", [ProbeType::HttpLiveness->value]),
        range(1, 5),
    );

    $snapshot = t19JudgeSnapshot($judges);
    $provider = new JudgeSetProvider(t19JudgeBudgetTracker(), JudgeSelectionStrategy::All);

    $result = $provider->select($snapshot, ProbeType::HttpLiveness, 3);

    expect($result)->toHaveCount(3);
});
