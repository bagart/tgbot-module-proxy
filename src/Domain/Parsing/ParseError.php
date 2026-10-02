<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Parsing;

use JsonSerializable;

/**
 * A single parsing failure attached to a specific input line.
 *
 * Serialized alongside ParseResult for diagnostics; no secrets in rawLine.
 */
final readonly class ParseError implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly int $line,
        public readonly string $rawLine,
        public readonly ParseErrorCode $code,
        public readonly ?string $detail,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'line' => $this->line,
            'rawLine' => $this->rawLine,
            'code' => $this->code->value,
            'detail' => $this->detail,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    public static function fromJson(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? 1;

        return match ($schemaVersion) {
            1 => self::fromJsonV1($data),
            default => throw new \RuntimeException("Unsupported ParseError schemaVersion: {$schemaVersion}"),
        };
    }

    private static function fromJsonV1(array $data): self
    {
        return new self(
            line: (int) $data['line'],
            rawLine: (string) $data['rawLine'],
            code: ParseErrorCode::from($data['code']),
            detail: $data['detail'] ?? null,
        );
    }
}
