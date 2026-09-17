<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Application;

/**
 * Standard result DTO for application queries.
 */
final readonly class QueryResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public bool $found,
        public ?array $data = null,
        public int $totalCount = 0,
        public ?string $errorKey = null,
    ) {}

    public static function found(array $data, int $totalCount = 1): self
    {
        return new self(found: true, data: $data, totalCount: $totalCount);
    }

    public static function empty(): self
    {
        return new self(found: false, totalCount: 0);
    }

    public static function notFound(string $errorKey = 'not_found'): self
    {
        return new self(found: false, errorKey: $errorKey);
    }
}
