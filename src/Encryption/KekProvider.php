<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Encryption;

use RuntimeException;

/**
 * Source of Key Encryption Keys (plan §10.12 п.13): resolves the raw key
 * material for the current KEK version and for historical versions still
 * needed to unwrap DEKs between rotations. Implementations return ready-to-use
 * binary AES-256 keys and never expose or log the material.
 */
interface KekProvider
{
    /**
     * Version stamped into newly sealed envelopes and used for wrapping DEKs.
     */
    public function currentVersion(): string;

    /**
     * Binary key for the given KEK version.
     *
     * @throws RuntimeException When no material is configured for the version.
     */
    public function keyFor(string $version): string;
}
