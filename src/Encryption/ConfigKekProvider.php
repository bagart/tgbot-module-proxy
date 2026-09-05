<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Encryption;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Default KekProvider: reads the KEK source once from
 * `config('proxy-operations.encryption')` — dedicated `PROXY_ENC_KEY` env with
 * an APP_KEY-derived fallback for dev/test posture (`fallback_to_app_key`).
 * Env is consumed exclusively at the config layer (platform rule); this class
 * sees only the resolved repository. Historical versions resolve through the
 * `historical_keks` map until rewrap retires them.
 */
final class ConfigKekProvider implements KekProvider
{
    public function __construct(private readonly Repository $config)
    {
        if ($this->currentMaterial() === '') {
            throw new RuntimeException('No KEK material is configured: set PROXY_ENC_KEY or enable fallback_to_app_key with a usable APP_KEY.');
        }
    }

    public function currentVersion(): string
    {
        $version = trim((string) $this->config->get('proxy-operations.encryption.key_version', 'k1'));

        if ($version === '') {
            throw new RuntimeException('The encryption.key_version configuration value must not be empty.');
        }

        return $version;
    }

    public function keyFor(string $version): string
    {
        $material = $version === $this->currentVersion()
            ? $this->currentMaterial()
            : $this->historicalMaterial($version);

        if ($material === '') {
            throw new RuntimeException(sprintf('No KEK material is configured for KEK version "%s".', $version));
        }

        return hash('sha256', $material, true);
    }

    private function currentMaterial(): string
    {
        $material = trim((string) $this->config->get('proxy-operations.encryption.kek', ''));

        if ($material === '' && (bool) $this->config->get('proxy-operations.encryption.fallback_to_app_key', false)) {
            $material = trim((string) $this->config->get('app.key', ''));
        }

        return $material;
    }

    private function historicalMaterial(string $version): string
    {
        /** @var array<string, mixed> $historical */
        $historical = $this->config->get('proxy-operations.encryption.historical_keks', []);

        return trim((string) ($historical[$version] ?? ''));
    }
}
