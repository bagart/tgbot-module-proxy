<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Cache;

use JsonSerializable;
use RuntimeException;

/**
 * A cached raw observation shared across all tenants (plan §11.7, R6.4).
 * Construction fails closed: only SharedCacheValueKind payloads are accepted,
 * and any payload key that looks like tenant interpretation (health, score,
 * lifecycle, verification, …) or a secret (credentials, auth headers,
 * cookies, response bodies) is rejected — INV-005.
 */
final readonly class SharedCacheValue implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * Substring patterns (case-insensitive) that mark a payload key as tenant
     * interpretation and make the whole value unsharable.
     */
    private const array FORBIDDEN_INTERPRETATION_PATTERNS = [
        'health',
        'score',
        'lifecycle',
        'verified',
        'eligibility',
        'quarantine',
        'verdict',
        'classification',
        'tier',
        'usable',
        'state',
    ];

    /**
     * Substring patterns (case-insensitive) that mark a payload key as
     * secret-bearing; such data must never enter the shared cache. Response
     * bodies are excluded at the kind level, not here — `body_hash` and
     * `content_length` are allowlisted raw measurements (R6.4).
     */
    private const array FORBIDDEN_SECRET_PATTERNS = [
        'password',
        'secret',
        'authorization',
        'cookie',
        'token',
    ];

    /**
     * @param  array<string, int|float|string|bool|null>  $payload  Flat raw-measurement fields.
     */
    public function __construct(
        public readonly SharedCacheValueKind $kind,
        public readonly array $payload,
    ) {
        self::assertSharable($this->payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'kind' => $this->kind->value,
            'payload' => $this->payload,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized or the kind is unknown.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported SharedCacheValue schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the kind is not on the allowlist.
     */
    private static function fromJsonV1(array $data): self
    {
        $kind = SharedCacheValueKind::tryFrom((string) $data['kind']);

        if ($kind === null) {
            throw new RuntimeException('SharedCacheValue kind is not on the allowlist.');
        }

        return new self(
            kind: $kind,
            payload: (array) $data['payload'],
        );
    }

    /**
     * @param  array<string, int|float|string|bool|null>  $payload
     */
    private static function assertSharable(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('SharedCacheValue payload keys must be strings.');
            }

            if (! is_scalar($value) && $value !== null) {
                throw new RuntimeException('SharedCacheValue payload values must be scalar.');
            }

            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $key));

            foreach (self::FORBIDDEN_INTERPRETATION_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    throw new RuntimeException('SharedCacheValue must not carry tenant interpretation keys.');
                }
            }

            foreach (self::FORBIDDEN_SECRET_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    throw new RuntimeException('SharedCacheValue must not carry secret-bearing keys.');
                }
            }
        }
    }
}
