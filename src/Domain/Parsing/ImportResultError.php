<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Parsing;

use JsonSerializable;

/**
 * A single parse error within an import result, attached to the offending line.
 *
 * Secrets are never included — only the line number, error code, and optional
 * diagnostic detail (which may reference the truncated raw line for non-secret
 * formats).
 */
final readonly class ImportResultError implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly int $line,
        public readonly string $code,
        public readonly ?string $detail,
    ) {
    }

    public static function fromParseError(ParseError $error): self
    {
        return new self(
            line: $error->line,
            code: $error->code->value,
            detail: $error->detail,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'line' => $this->line,
            'code' => $this->code,
            'detail' => $this->detail,
            'schemaVersion' => self::SCHEMA_VERSION,
        ];
    }

    public static function fromJson(array $data): self
    {
        $schemaVersion = $data['schemaVersion'] ?? 1;

        return match ($schemaVersion) {
            1 => self::fromJsonV1($data),
            default => throw new \RuntimeException("Unsupported ImportResultError schemaVersion: {$schemaVersion}"),
        };
    }

    private static function fromJsonV1(array $data): self
    {
        return new self(
            line: (int) $data['line'],
            code: (string) $data['code'],
            detail: $data['detail'] ?? null,
        );
    }
}
