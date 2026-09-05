<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Snapshot\JudgeDescriptor;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeSetSnapshot;
use BAGArt\ProxyOperations\Domain\Snapshot\JudgeTrustTier;

function makeJudge(): JudgeDescriptor
{
    return new JudgeDescriptor(
        id: 'judge-1',
        url: 'https://judge.example.org/echo',
        region: 'eu-central',
        protocol: 'https',
        capabilities: ['liveness', 'anonymity'],
        rateLimitPerMinute: 60,
        trustTier: JudgeTrustTier::SelfHosted,
    );
}

it('round-trips through JSON', function (): void {
    $snapshot = new JudgeSetSnapshot(
        setId: 'default',
        version: 3,
        judges: [makeJudge()],
        frozenAt: '2026-08-26T00:00:00+00:00',
    );

    $restored = JudgeSetSnapshot::fromJson($snapshot->jsonSerialize());

    expect($restored->setId)->toBe('default')
        ->and($restored->version)->toBe(3)
        ->and($restored->frozenAt)->toBe('2026-08-26T00:00:00+00:00')
        ->and($restored->judges)->toHaveCount(1)
        ->and($restored->judges[0])->toEqual(makeJudge());
});

it('requires the version field on deserialization', function (): void {
    $data = (new JudgeSetSnapshot('default', 1, [makeJudge()], '2026-08-26T00:00:00+00:00'))->jsonSerialize();
    unset($data['version']);

    JudgeSetSnapshot::fromJson($data);
})->throws(RuntimeException::class, 'mandatory version');

it('rejects unsupported schema versions', function (): void {
    JudgeSetSnapshot::fromJson(['schemaVersion' => 99, 'version' => 1]);
})->throws(RuntimeException::class, 'schemaVersion');

it('grants HMAC protection only to self-hosted judges', function (): void {
    expect(JudgeTrustTier::SelfHosted->isHmacProtected())->toBeTrue()
        ->and(JudgeTrustTier::PublicHttps->isHmacProtected())->toBeFalse();
});
