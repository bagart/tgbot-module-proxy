<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Cache;

/**
 * Explicit allowlist of what may be stored under a shared cache key:
 * raw probe measurements only (plan §11.7 R6.4). Tenant interpretation
 * (health, scores, lifecycle, verification) has no kind here by design —
 * it cannot even be named, so it cannot leak into the shared cache (INV-005).
 */
enum SharedCacheValueKind: string
{
    case HttpMeasurement = 'http_measurement';
    case TimingMeasurement = 'timing_measurement';
    case ExitIpObservation = 'exit_ip_observation';
    case DnsObservation = 'dns_observation';
    case MarkerResult = 'marker_result';
    case AnonymityHeaderFlags = 'anonymity_header_flags';
    // Negative caching marker (T29; plan §11.7): "this probe failed recently".
    // Raw operational fact, not tenant interpretation — INV-005 holds.
    case NegativeProbeResult = 'negative_probe_result';
}
