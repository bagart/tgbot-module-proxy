<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Lease;

use JsonSerializable;
use RuntimeException;

/**
 * What a consumer asks the selector for (plan §11.25). Readonly, additive;
 * no credentials ever — the selector works with access ids and policies.
 */
final readonly class SelectionCriteria implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  string|null  $poolId  Null = the tenant's single dynamic pool.
     * @param  list<string>  $excludeAccessIds  Access ids to never hand out.
     * @param  int|null  $maxStalenessSeconds  Freshness gate; null = no gate.
     */
    public function __construct(
        public readonly string $holder,
        public readonly ?string $poolId = null,
        public readonly string $purpose = 'session',
        public readonly int $count = 1,
        public readonly ?string $requiredProtocol = null,
        public readonly ?string $requiredCountry = null,
        public readonly ?bool $telegramUsableOnly = null,
        public readonly array $excludeAccessIds = [],
        public readonly ?int $maxStalenessSeconds = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'holder' => $this->holder,
            'poolId' => $this->poolId,
            'purpose' => $this->purpose,
            'count' => $this->count,
            'requiredProtocol' => $this->requiredProtocol,
            'requiredCountry' => $this->requiredCountry,
            'telegramUsableOnly' => $this->telegramUsableOnly,
            'excludeAccessIds' => $this->excludeAccessIds,
            'maxStalenessSeconds' => $this->maxStalenessSeconds,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException If the format is not recognized.
     */
    public static function fromJson(array $data): self
    {
        return match ($data['schemaVersion'] ?? self::SCHEMA_VERSION) {
            self::SCHEMA_VERSION => new self(
                holder: (string) $data['holder'],
                poolId: isset($data['poolId']) ? (string) $data['poolId'] : null,
                purpose: (string) ($data['purpose'] ?? 'session'),
                count: (int) ($data['count'] ?? 1),
                requiredProtocol: isset($data['requiredProtocol']) ? (string) $data['requiredProtocol'] : null,
                requiredCountry: isset($data['requiredCountry']) ? (string) $data['requiredCountry'] : null,
                telegramUsableOnly: isset($data['telegramUsableOnly']) ? (bool) $data['telegramUsableOnly'] : null,
                excludeAccessIds: array_map(static fn ($id): string => (string) $id, (array) ($data['excludeAccessIds'] ?? [])),
                maxStalenessSeconds: isset($data['maxStalenessSeconds']) ? (int) $data['maxStalenessSeconds'] : null,
            ),
            default => throw new RuntimeException('Unsupported SelectionCriteria schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }
}
