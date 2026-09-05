<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Parsing;

use JsonSerializable;

/**
 * Structured result of an import run: counts, first-N errors, and a batch ID
 * for tracking. Serializable for bot responses / API responses.
 */
final readonly class ImportResult implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    /**
     * @param  ImportResultError[]  $errors
     */
    public function __construct(
        public readonly int $totalLines,
        public readonly int $created,
        public readonly int $skipped,
        public readonly int $parseErrors,
        public readonly int $staged,
        public readonly array $errors,
        public readonly string $importBatchId,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'totalLines' => $this->totalLines,
            'created' => $this->created,
            'skipped' => $this->skipped,
            'parseErrors' => $this->parseErrors,
            'staged' => $this->staged,
            'errors' => array_map(
                static fn (ImportResultError $e): array => $e->jsonSerialize(),
                $this->errors,
            ),
            'importBatchId' => $this->importBatchId,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    public static function fromJson(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? 1;

        return match ($schemaVersion) {
            1 => self::fromJsonV1($data),
            default => throw new \RuntimeException("Unsupported ImportResult schemaVersion: {$schemaVersion}"),
        };
    }

    private static function fromJsonV1(array $data): self
    {
        return new self(
            totalLines: (int) $data['totalLines'],
            created: (int) $data['created'],
            skipped: (int) $data['skipped'],
            parseErrors: (int) $data['parseErrors'],
            staged: (int) $data['staged'],
            errors: array_map(
                static fn (array $e): ImportResultError => ImportResultError::fromJson($e),
                (array) ($data['errors'] ?? []),
            ),
            importBatchId: (string) $data['importBatchId'],
        );
    }
}
