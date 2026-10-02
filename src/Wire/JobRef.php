<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Wire;

use JsonSerializable;
use RuntimeException;

/**
 * Identity triple of one audit execution: Job → Attempt → TaskDelivery
 * (plan §11.9). Idempotency key for worker results is taskId+attemptId;
 * the ids are never interchangeable with other idempotency sources.
 */
final readonly class JobRef implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $jobId,
        public readonly string $attemptId,
        public readonly string $taskId,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'jobId' => $this->jobId,
            'attemptId' => $this->attemptId,
            'taskId' => $this->taskId,
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
            default => throw new RuntimeException('Unsupported JobRef schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        return new self(
            jobId: (string) $data['jobId'],
            attemptId: (string) $data['attemptId'],
            taskId: (string) $data['taskId'],
        );
    }
}
