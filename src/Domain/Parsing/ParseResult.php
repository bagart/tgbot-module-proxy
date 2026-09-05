<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Parsing;

use JsonSerializable;

/**
 * Aggregate result of parsing a proxy list: successful entries + errors + counters.
 */
final readonly class ParseResult implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  ParsedEntry[]  $entries
     * @param  ParseError[]  $errors
     */
    public function __construct(
        public readonly array $entries,
        public readonly array $errors,
        public readonly int $totalLines,
        public readonly int $parsedCount,
        public readonly int $errorCount,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'entries' => array_map(
                static fn (ParsedEntry $e): array => $e->jsonSerialize(),
                $this->entries,
            ),
            'errors' => array_map(
                static fn (ParseError $e): array => $e->jsonSerialize(),
                $this->errors,
            ),
            'totalLines' => $this->totalLines,
            'parsedCount' => $this->parsedCount,
            'errorCount' => $this->errorCount,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    public static function fromJson(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? 1;

        return match ($schemaVersion) {
            1 => self::fromJsonV1($data),
            default => throw new \RuntimeException("Unsupported ParseResult schemaVersion: {$schemaVersion}"),
        };
    }

    private static function fromJsonV1(array $data): self
    {
        return new self(
            entries: array_map(
                static fn (array $e): ParsedEntry => ParsedEntry::fromJson($e),
                (array) ($data['entries'] ?? []),
            ),
            errors: array_map(
                static fn (array $e): ParseError => ParseError::fromJson($e),
                (array) ($data['errors'] ?? []),
            ),
            totalLines: (int) ($data['totalLines'] ?? 0),
            parsedCount: (int) ($data['parsedCount'] ?? 0),
            errorCount: (int) ($data['errorCount'] ?? 0),
        );
    }
}
