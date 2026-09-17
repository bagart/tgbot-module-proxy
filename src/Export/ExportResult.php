<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

use Illuminate\Support\Str;

/**
 * Export result — content + metadata (plan §11.28).
 */
final readonly class ExportResult
{
    public function __construct(
        public string $content,
        public string $mimeType,
        public string $filename,
        public string $exportId,
        public int $recordCount,
    ) {}

    public static function make(string $content, string $mimeType, string $extension, int $recordCount): self
    {
        return new self(
            content: $content,
            mimeType: $mimeType,
            filename: 'proxy-export-'.Str::ulid().'.'.$extension,
            exportId: (string) Str::ulid(),
            recordCount: $recordCount,
        );
    }
}
