<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Contract for proxy export formatters (plan §11.10, §11.28).
 * Each formatter produces a specific output format from export views.
 */
interface ExportFormatter
{
    /**
     * @param  list<ExportView>  $views
     */
    public function format(array $views): string;

    public function mimeType(): string;

    public function fileExtension(): string;
}
