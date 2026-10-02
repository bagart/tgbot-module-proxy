<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValueKind;

/**
 * Shared raw-probe cache policy (T29; plan §§11.7, 11.14, R6.2/R6.4),
 * frozen from config `proxy-operations.audit.cache`. Carries the per-kind
 * TTL table, the negative-caching TTL and the key prefix used by
 * LaravelCacheProbeCache.
 */
final readonly class CachePolicy
{
    /**
     * @param  bool  $enabled  Shared cache master switch (§11.14: ON at stage 6).
     * @param  string  $keyPrefix  Cache key prefix; contains no secrets (R6.2).
     * @param  int  $negativeTtlSeconds  TTL for NegativeProbeResult markers.
     * @param  array<string, int>  $ttlByKind  SharedCacheValueKind value => TTL seconds.
     * @param  int  $defaultTtlSeconds  TTL for kinds missing from $ttlByKind.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $keyPrefix,
        public readonly int $negativeTtlSeconds,
        public readonly array $ttlByKind,
        public readonly int $defaultTtlSeconds,
    ) {
    }

    /**
     * TTL for one value kind; falls back to the default TTL for kinds that
     * have no explicit entry.
     */
    public function ttlForKind(SharedCacheValueKind $kind): int
    {
        return $this->ttlByKind[$kind->value] ?? $this->defaultTtlSeconds;
    }

    /**
     * @param  array<string, mixed>  $config  proxy-operations.audit.cache section.
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, int> $ttlByKind */
        $ttlByKind = (array) ($config['ttl_by_kind'] ?? []);

        return new self(
            enabled: (bool) ($config['enabled'] ?? false),
            keyPrefix: (string) ($config['key_prefix'] ?? 'proxy:probe-cache:'),
            negativeTtlSeconds: (int) ($config['negative_ttl_seconds'] ?? 120),
            ttlByKind: $ttlByKind,
            defaultTtlSeconds: (int) ($config['default_ttl_seconds'] ?? 1800),
        );
    }
}
