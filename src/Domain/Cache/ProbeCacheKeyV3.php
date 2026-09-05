<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Cache;

use BAGArt\ProxyOperations\Domain\Identity\CredentialFingerprint;
use BAGArt\ProxyOperations\Domain\Identity\EndpointIdentity;
use JsonSerializable;
use RuntimeException;

/**
 * Deterministic identity of one shared raw-probe cache entry (plan §11.7,
 * R6.2, §11.39 п.12). Every field that can change the raw observation result
 * participates; `checker_region` is metadata and deliberately absent.
 *
 * INV-017: no build metadata / git SHAs here — only the summarized
 * `toolSemanticsVersion` (tool_name + tool_version + tool_protocol_version).
 * Build-level identifiers fragment the shared cache without changing results.
 *
 * Secrets never participate in the key: credentials enter only through the
 * CredentialFingerprint HMAC.
 */
final readonly class ProbeCacheKeyV3 implements JsonSerializable
{
    public const int SCHEMA_VERSION = 3;

    public function __construct(
        public readonly EndpointIdentity $endpointIdentity,
        public readonly CredentialFingerprint $credentialFingerprint,
        public readonly string $checkerNodeId,
        public readonly string $egressIdentity,
        public readonly int $judgeSetVersion,
        public readonly int $telegramDcSetVersion,
        public readonly int $probeProfileVersion,
        public readonly string $probeSemanticsVersion,
        public readonly string $toolSemanticsVersion,
    ) {}

    /**
     * Stable canonical string: fields are always emitted in constructor order
     * and length-prefixed, so no value can bleed into a neighbouring field
     * and construction order is irrelevant.
     */
    public function toString(): string
    {
        return self::frame('probe-cache-key-v3')
            .self::frame($this->endpointIdentity->toString())
            .self::frame($this->credentialFingerprint->value)
            .self::frame($this->checkerNodeId)
            .self::frame($this->egressIdentity)
            .self::frame((string) $this->judgeSetVersion)
            .self::frame((string) $this->telegramDcSetVersion)
            .self::frame((string) $this->probeProfileVersion)
            .self::frame($this->probeSemanticsVersion)
            .self::frame($this->toolSemanticsVersion);
    }

    /**
     * Shared-cache lookup hash derived from toString().
     */
    public function toHash(): string
    {
        return hash('sha256', $this->toString());
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'endpoint' => $this->endpointIdentity->jsonSerialize(),
            'credential' => $this->credentialFingerprint->jsonSerialize(),
            'checkerNodeId' => $this->checkerNodeId,
            'egressIdentity' => $this->egressIdentity,
            'judgeSetVersion' => $this->judgeSetVersion,
            'telegramDcSetVersion' => $this->telegramDcSetVersion,
            'probeProfileVersion' => $this->probeProfileVersion,
            'probeSemanticsVersion' => $this->probeSemanticsVersion,
            'toolSemanticsVersion' => $this->toolSemanticsVersion,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => self::fromJsonV3($data),
            default => throw new RuntimeException('Unsupported ProbeCacheKeyV3 schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV3(array $data): self
    {
        return new self(
            endpointIdentity: EndpointIdentity::fromJson((array) $data['endpoint']),
            credentialFingerprint: CredentialFingerprint::fromJson((array) $data['credential']),
            checkerNodeId: (string) $data['checkerNodeId'],
            egressIdentity: (string) $data['egressIdentity'],
            judgeSetVersion: (int) $data['judgeSetVersion'],
            telegramDcSetVersion: (int) $data['telegramDcSetVersion'],
            probeProfileVersion: (int) $data['probeProfileVersion'],
            probeSemanticsVersion: (string) $data['probeSemanticsVersion'],
            toolSemanticsVersion: (string) $data['toolSemanticsVersion'],
        );
    }

    private function frame(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
