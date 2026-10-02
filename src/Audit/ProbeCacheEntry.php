<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Audit;

use BAGArt\ProxyOperations\Domain\Cache\SharedCacheValue;
use Illuminate\Support\Carbon;
use JsonSerializable;
use RuntimeException;

/**
 * One shared raw-probe cache entry (T29; plan §11.7): a SharedCacheValue
 * paired with the metadata consumers need. `ageSeconds` is resolved at
 * read time from `cachedAtMs` — the store keeps only the absolute write
 * timestamp, so age stays meaningful across clock-domain boundaries.
 */
final readonly class ProbeCacheEntry implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  int  $cachedAtMs  Absolute write time (epoch milliseconds).
     * @param  int  $ageSeconds  Age of the entry at read time.
     */
    public function __construct(
        public readonly SharedCacheValue $value,
        public readonly int $cachedAtMs,
        public readonly int $ageSeconds,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'value' => $this->value->jsonSerialize(),
            'cachedAtMs' => $this->cachedAtMs,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  int|null  $nowMs  Read-time clock (epoch ms); defaults to real time.
     *
     * @throws RuntimeException If the format is not recognized.
     */
    public static function fromJson(array $data, ?int $nowMs = null): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data, $nowMs ?? self::nowMs()),
            default => throw new RuntimeException('Unsupported ProbeCacheEntry schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data, int $nowMs): self
    {
        $cachedAtMs = (int) ($data['cachedAtMs'] ?? 0);

        return new self(
            value: SharedCacheValue::fromJson((array) $data['value']),
            cachedAtMs: $cachedAtMs,
            ageSeconds: max(0, intdiv($nowMs - $cachedAtMs, 1000)),
        );
    }

    /**
     * Carbon is used deliberately: it respects Carbon::setTestNow, so the
     * age stays testable and consistent with the rest of the Laravel stack.
     */
    private static function nowMs(): int
    {
        return Carbon::now()->getTimestampMs();
    }
}
