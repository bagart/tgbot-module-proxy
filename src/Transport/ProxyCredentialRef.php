<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Transport;

use BAGArt\ProxyOperations\Tool\CredentialChannel;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use JsonSerializable;
use RuntimeException;

/**
 * Immutable credential reference — carries a channel descriptor and optional
 * username, never the secret itself (plan §11.35 п.5, INV-013).
 *
 * The actual credential is delivered through the CredentialChannel at
 * execution time; this DTO is a lightweight pointer used by ProxyConfig.
 */
final readonly class ProxyCredentialRef implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly ?string $username,
        public readonly CredentialChannel $channel,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'username' => $this->username,
            'channel' => $this->channel->mode()->value,
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
            default => throw new RuntimeException('Unsupported ProxyCredentialRef schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $channelMode = (string) ($data['channel'] ?? '');

        $channel = match ($channelMode) {
            'stdin' => new StdinChannel(),
            'file_descriptor' => new FileDescriptorChannel(
                fileDescriptor: (int) ($data['fileDescriptor'] ?? 3),
            ),
            default => throw new RuntimeException("Unsupported CredentialChannel mode: {$channelMode}"),
        };

        return new self(
            username: isset($data['username']) ? (string) $data['username'] : null,
            channel: $channel,
        );
    }
}
