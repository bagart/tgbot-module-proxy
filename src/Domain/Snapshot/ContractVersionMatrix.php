<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Snapshot;

use JsonSerializable;
use RuntimeException;

/**
 * Contract Version Matrix (plan §11.12): single versioning table for the
 * worker/parser/projection/event/API contracts, replacing scattered version
 * constants. Stamped into audit jobs so every party can verify wire
 * compatibility before exchanging payloads.
 */
final readonly class ContractVersionMatrix implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  array<string, ContractVersionEntry>  $contracts  Contract name → entry.
     */
    public function __construct(
        public readonly array $contracts,
    ) {}

    /**
     * The plan §11.12 contract table as shipped at Stage 0 (all contracts V1).
     */
    public static function planSection1112(): self
    {
        $entry = static fn (string $name, ContractCompatibility $compatibility): ContractVersionEntry => new ContractVersionEntry(
            name: $name,
            currentVersion: 1,
            compatibility: $compatibility,
            compatibleVersions: [1],
        );

        return new self(contracts: [
            'audit_task_result' => $entry('audit_task_result', ContractCompatibility::Additive),
            'parser_request_response' => $entry('parser_request_response', ContractCompatibility::Additive),
            'verified_proxy_projection' => $entry('verified_proxy_projection', ContractCompatibility::Additive),
            'domain_event_envelope' => $entry('domain_event_envelope', ContractCompatibility::Additive),
            'proxy_selector' => $entry('proxy_selector', ContractCompatibility::Semantic),
            'application_api' => $entry('application_api', ContractCompatibility::Versioned),
        ]);
    }

    public function entry(string $name): ?ContractVersionEntry
    {
        return $this->contracts[$name] ?? null;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'contracts' => array_map(
                static fn (ContractVersionEntry $entry): array => $entry->jsonSerialize(),
                $this->contracts,
            ),
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
            self::SCHEMA_VERSION => self::fromJsonV1($data),
            default => throw new RuntimeException('Unsupported ContractVersionMatrix schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $contracts = [];

        foreach ((array) ($data['contracts'] ?? []) as $name => $entry) {
            $deserialized = ContractVersionEntry::fromJson((array) $entry);
            $contracts[(string) $name] = $deserialized;
        }

        return new self(contracts: $contracts);
    }
}
