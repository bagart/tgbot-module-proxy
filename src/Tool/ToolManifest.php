<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use InvalidArgumentException;
use JsonSerializable;
use RuntimeException;

/**
 * Published description of an allowlisted tool (plan §11.39 пп.10–11): name,
 * version, api_version, capabilities, limits and security block. The worker
 * validates compatibility against it at start-up — never `if ($v === '2.4')`.
 */
final readonly class ToolManifest implements JsonSerializable
{
    public const int SCHEMA_VERSION = 1;

    public function __construct(
        public readonly ToolId $name,
        public readonly string $version,
        public readonly int $apiVersion,
        public readonly ToolCapabilities $capabilities,
        public readonly ToolLimits $limits,
        public readonly ToolSecurity $security,
    ) {
        if ($this->version === '') {
            throw new InvalidArgumentException('Tool version must not be empty.');
        }

        if ($this->apiVersion < 1) {
            throw new InvalidArgumentException('Tool apiVersion must be >= 1.');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name->value,
            'version' => $this->version,
            'apiVersion' => $this->apiVersion,
            'capabilities' => [
                'probeTypes' => array_map(
                    static fn ($probeType): string => $probeType->value,
                    $this->capabilities->probeTypes,
                ),
                'protocols' => $this->capabilities->protocols,
                'inputSchemaVersion' => $this->capabilities->inputSchemaVersion,
                'outputSchemaVersion' => $this->capabilities->outputSchemaVersion,
            ],
            'limits' => [
                'maxExecutionTimeSeconds' => $this->limits->maxExecutionTimeSeconds,
                'maxOutputBytes' => $this->limits->maxOutputBytes,
            ],
            'security' => [
                'network' => $this->security->network,
                'filesystem' => $this->security->filesystem,
                'privileges' => $this->security->privileges,
            ],
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
            default => throw new RuntimeException('Unsupported ToolManifest schemaVersion: '.var_export($data['schemaVersion'], true)),
        };
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function fromJsonV1(array $data): self
    {
        $capabilities = self::requireArray($data, 'capabilities');
        $limits = self::requireArray($data, 'limits');
        $security = self::requireArray($data, 'security');

        return new self(
            name: new ToolId((string) self::require($data, 'name')),
            version: (string) self::require($data, 'version'),
            apiVersion: (int) self::require($data, 'apiVersion'),
            capabilities: new ToolCapabilities(
                probeTypes: array_map(
                    static fn (string $value): ProbeType => ProbeType::from($value),
                    array_map(strval(...), self::requireList($capabilities, 'probeTypes')),
                ),
                protocols: array_map(strval(...), self::requireList($capabilities, 'protocols')),
                inputSchemaVersion: (int) self::require($capabilities, 'inputSchemaVersion'),
                outputSchemaVersion: (int) self::require($capabilities, 'outputSchemaVersion'),
            ),
            limits: new ToolLimits(
                maxExecutionTimeSeconds: (int) self::require($limits, 'maxExecutionTimeSeconds'),
                maxOutputBytes: (int) self::require($limits, 'maxOutputBytes'),
            ),
            security: new ToolSecurity(
                network: (string) self::require($security, 'network'),
                filesystem: (string) self::require($security, 'filesystem'),
                privileges: (string) self::require($security, 'privileges'),
            ),
        );
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private static function require(array $data, string $key): mixed
    {
        return isset($data[$key])
            ? $data[$key]
            : throw new InvalidArgumentException("ToolManifest is missing required key '{$key}'.");
    }

    /**
     * @param  array<string,mixed>  $data
     * @return list<mixed>
     */
    private static function requireList(array $data, string $key): array
    {
        $value = self::require($data, $key);

        return is_array($value) && array_is_list($value)
            ? $value
            : throw new InvalidArgumentException("ToolManifest key '{$key}' must be a list.");
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private static function requireArray(array $data, string $key): array
    {
        $value = self::require($data, $key);
        ! is_array($value) && throw new InvalidArgumentException("ToolManifest key '{$key}' must be an object.");

        return $value;
    }
}
