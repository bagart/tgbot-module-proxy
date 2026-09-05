<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Pool;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Domain\Lifecycle\AccessState;
use JsonSerializable;
use RuntimeException;

/**
 * Conjunctive filter over pool candidates (plan §11.25). Persisted as the
 * `proxy_pools.predicate` JSON; a null field is a pass-through. Only fields
 * with a real backing in the schema participate (no tags/country columns
 * exist yet — they are added here when the schema grows).
 */
final readonly class PoolPredicate implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  list<string>|null  $states  AccessState values.
     * @param  list<string>|null  $protocols  ProxyProtocol values.
     * @param  float|null  $minHealthScore  Null = no floor.
     * @param  bool|null  $telegramUsableOnly  Null = no Telegram filter.
     */
    public function __construct(
        public readonly ?array $states = null,
        public readonly ?array $protocols = null,
        public readonly ?float $minHealthScore = null,
        public readonly ?bool $telegramUsableOnly = null,
    ) {}

    public function matches(PoolCandidateView $candidate): bool
    {
        return $this->firstMismatch($candidate) === null;
    }

    /**
     * The first unsatisfied predicate dimension as a decision-log reason
     * code, or null when the candidate matches (plan §11.25 — explainable
     * skips).
     */
    public function firstMismatch(PoolCandidateView $candidate): ?SelectionReasonCode
    {
        if ($this->states !== null && ! in_array($candidate->state->value, $this->states, true)) {
            return SelectionReasonCode::PredicateStateMismatch;
        }

        if ($this->protocols !== null && ! in_array($candidate->protocol->value, $this->protocols, true)) {
            return SelectionReasonCode::PredicateProtocolMismatch;
        }

        if ($this->minHealthScore !== null && ($candidate->healthScore === null || $candidate->healthScore < $this->minHealthScore)) {
            return SelectionReasonCode::PredicateHealthBelowFloor;
        }

        if ($this->telegramUsableOnly === true && $candidate->telegramUsableNow !== true) {
            return SelectionReasonCode::PredicateTelegramFilter;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'states' => $this->states,
            'protocols' => $this->protocols,
            'minHealthScore' => $this->minHealthScore,
            'telegramUsableOnly' => $this->telegramUsableOnly,
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
                states: self::stringsOrNull($data['states'] ?? null),
                protocols: self::stringsOrNull($data['protocols'] ?? null),
                minHealthScore: ($data['minHealthScore'] ?? null) === null ? null : (float) $data['minHealthScore'],
                telegramUsableOnly: ($data['telegramUsableOnly'] ?? null) === null ? null : (bool) $data['telegramUsableOnly'],
            ),
            default => throw new RuntimeException('Unsupported PoolPredicate schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @return list<string>|null
     */
    private static function stringsOrNull(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return array_map(static fn (mixed $item): string => (string) $item, (array) $value);
    }
}