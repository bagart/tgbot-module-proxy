<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Probe;

/**
 * Pure data row of the plan §11.17 profile table: probe set, series size,
 * timeout tier, relative cost and optional bandwidth cap.
 */
final readonly class ProbeProfileDefinition
{
    /**
     * @param  list<ProbeType>  $probes
     * @param  positive-int  $latencySeries
     * @param  float  $relativeCost  Cost relative to the standard profile (~1×)
     * @param  int|null  $bandwidthCapBytes  Upload cap for bandwidth probing, null when not applicable
     */
    public function __construct(
        public ProbeProfile $profile,
        public array $probes,
        public int $latencySeries,
        public TimeoutTier $timeout,
        public float $relativeCost,
        public ?int $bandwidthCapBytes = null,
    ) {
    }

    public static function forProfile(ProbeProfile $profile): self
    {
        return match ($profile) {
            ProbeProfile::Light => new self(
                profile: $profile,
                probes: [ProbeType::HttpLiveness],
                latencySeries: 3,
                timeout: TimeoutTier::Aggressive,
                relativeCost: 0.3,
            ),
            ProbeProfile::Standard => new self(
                profile: $profile,
                probes: [ProbeType::HttpLiveness, ProbeType::HeaderMarker, ProbeType::AnonymityHeaders],
                latencySeries: 5,
                timeout: TimeoutTier::Standard,
                relativeCost: 1.0,
            ),
            // UDP/DNS probes are always on in deep; TG DC connectivity stays opt-in (not in the default set).
            ProbeProfile::Deep => new self(
                profile: $profile,
                probes: [
                    ProbeType::HttpLiveness,
                    ProbeType::HeaderMarker,
                    ProbeType::AnonymityHeaders,
                    ProbeType::UdpAssociate,
                    ProbeType::DnsResolution,
                ],
                latencySeries: 5,
                timeout: TimeoutTier::Generous,
                relativeCost: 2.5,
            ),
            // MTProto handshake applies only to MTPROTO endpoints; runners filter by protocol.
            ProbeProfile::Telegram => new self(
                profile: $profile,
                probes: [ProbeType::TelegramDcConnectivity, ProbeType::MtprotoHandshake],
                latencySeries: 3,
                timeout: TimeoutTier::Standard,
                relativeCost: 1.5,
            ),
            ProbeProfile::Bandwidth => new self(
                profile: $profile,
                probes: [ProbeType::BandwidthTransfer],
                latencySeries: 1,
                timeout: TimeoutTier::Generous,
                relativeCost: 1.2,
                bandwidthCapBytes: 1024 * 1024,
            ),
        };
    }
}
