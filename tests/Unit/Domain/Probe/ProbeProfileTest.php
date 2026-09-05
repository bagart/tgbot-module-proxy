<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Probe\ProbeProfile;
use BAGArt\ProxyOperations\Domain\Probe\ProbeProfileDefinition;
use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use BAGArt\ProxyOperations\Domain\Probe\TimeoutTier;

it('has a definition with a probe set for every profile', function (): void {
    foreach (ProbeProfile::cases() as $profile) {
        $definition = ProbeProfileDefinition::forProfile($profile);

        expect($definition)->toBeInstanceOf(ProbeProfileDefinition::class)
            ->and($definition->profile)->toBe($profile)
            ->and($definition->probes)->not->toBeEmpty()
            ->and($definition->latencySeries)->toBeGreaterThan(0)
            ->and($definition->relativeCost)->toBeGreaterThan(0.0);
    }
});

it('matches plan §11.17 profile rows', function (ProbeProfile $profile, array $probes, int $latencySeries, TimeoutTier $timeout, float $relativeCost, ?int $bandwidthCapBytes): void {
    $definition = ProbeProfileDefinition::forProfile($profile);

    expect($definition->probes)->toEqual($probes)
        ->and($definition->latencySeries)->toBe($latencySeries)
        ->and($definition->timeout)->toBe($timeout)
        ->and($definition->relativeCost)->toBe($relativeCost)
        ->and($definition->bandwidthCapBytes)->toBe($bandwidthCapBytes);
})->with([
    'light' => [ProbeProfile::Light, [ProbeType::HttpLiveness], 3, TimeoutTier::Aggressive, 0.3, null],
    'standard' => [ProbeProfile::Standard, [ProbeType::HttpLiveness, ProbeType::HeaderMarker, ProbeType::AnonymityHeaders], 5, TimeoutTier::Standard, 1.0, null],
    'deep' => [ProbeProfile::Deep, [
        ProbeType::HttpLiveness,
        ProbeType::HeaderMarker,
        ProbeType::AnonymityHeaders,
        ProbeType::UdpAssociate,
        ProbeType::DnsResolution,
    ], 5, TimeoutTier::Generous, 2.5, null],
    'telegram' => [ProbeProfile::Telegram, [ProbeType::TelegramDcConnectivity, ProbeType::MtprotoHandshake], 3, TimeoutTier::Standard, 1.5, null],
    'bandwidth' => [ProbeProfile::Bandwidth, [ProbeType::BandwidthTransfer], 1, TimeoutTier::Generous, 1.2, 1024 * 1024],
]);

it('caps bandwidth probing at 1 MB', function (): void {
    expect(ProbeProfileDefinition::forProfile(ProbeProfile::Bandwidth)->bandwidthCapBytes)->toBe(1_048_576);
});
