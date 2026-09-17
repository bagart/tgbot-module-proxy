<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * CSV export formatter — RFC 4180 compliant.
 */
final class CsvExportFormatter implements ExportFormatter
{
    /**
     * @param  list<ExportView>  $views
     */
    public function format(array $views): string
    {
        $handle = fopen('php://memory', 'r+');

        fputcsv($handle, [
            'protocol', 'host', 'port', 'credential', 'health_score',
            'state', 'telegram_usable', 'country',
        ]);

        foreach ($views as $view) {
            fputcsv($handle, [
                $view->protocol->value,
                $view->host,
                $view->port,
                $view->credential,
                $view->healthScore,
                $view->accessState,
                $view->telegramUsable === null ? '' : ($view->telegramUsable ? 'true' : 'false'),
                $view->country,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    public function fileExtension(): string
    {
        return 'csv';
    }
}
