<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tool;

use BAGArt\ProxyOperations\Domain\Probe\ProbeType;
use InvalidArgumentException;

/**
 * Capabilities published by a ProbeTool (plan §11.39 пп.8,10): the worker
 * checks compatibility against these at start-up instead of version sniffing.
 */
final readonly class ToolCapabilities
{
    /**
     * @param  list<ProbeType>  $probeTypes  Probe types this tool can execute.
     * @param  list<non-empty-string>  $protocols  Protocol identifiers behind the implementation detail (e.g. 'socks5', 'mtproto').
     * @param  positive-int  $inputSchemaVersion  Version of the minimal ProbeInput schema the tool accepts.
     * @param  positive-int  $outputSchemaVersion  Version of the raw-result schema the tool emits.
     */
    public function __construct(
        public readonly array $probeTypes,
        public readonly array $protocols,
        public readonly int $inputSchemaVersion,
        public readonly int $outputSchemaVersion,
    ) {
        if ($this->probeTypes === []) {
            throw new InvalidArgumentException('A tool must support at least one probe type.');
        }

        if ($this->protocols === []) {
            throw new InvalidArgumentException('A tool must declare at least one protocol.');
        }
    }
}
