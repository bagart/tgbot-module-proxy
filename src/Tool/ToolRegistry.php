<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use InvalidArgumentException;

/**
 * Allowlist toolId → manifest (plan §11.39 п.10): the ONLY resolution path to
 * an external tool. Callers cannot inject paths or executables — a ToolId plus
 * preloaded manifests is all this registry accepts (INV-012); the fixed
 * executable mapping lives inside tool adapters, outside caller reach.
 */
final readonly class ToolRegistry
{
    /**
     * @param  array<non-empty-string, ToolManifest>  $manifests  Keyed by manifest name for O(1) lookup.
     */
    public function __construct(
        private readonly array $manifests,
    ) {
        foreach ($this->manifests as $key => $manifest) {
            if ($key !== $manifest->name->value) {
                throw new InvalidArgumentException("Registry key '{$key}' must match the manifest name '{$manifest->name->value}'.");
            }
        }
    }

    /**
     * @throws UnknownToolException If the id is not on the allowlist.
     */
    public function resolve(ToolId $id): ToolManifest
    {
        return $this->manifests[$id->value]
            ?? throw new UnknownToolException("Unknown tool '{$id->value}'.");
    }

    /**
     * Everything the worker publishes over GET /capabilities so the start-up
     * compatibility check runs against manifests instead of version sniffing
     * (plan §11.39 пп.4,10; INV-020 keeps this off the execution plane).
     *
     * @return array<non-empty-string, ToolManifest>
     */
    public function manifests(): array
    {
        return $this->manifests;
    }
}
