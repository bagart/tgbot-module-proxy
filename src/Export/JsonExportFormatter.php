<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * JSON export formatter — full schema, credentials masked by default.
 */
final class JsonExportFormatter implements ExportFormatter
{
    /**
     * @param  list<ExportView>  $views
     */
    public function format(array $views): string
    {
        $data = array_map(static fn (ExportView $v): array => [
            'protocol' => $v->protocol->value,
            'host' => $v->host,
            'port' => $v->port,
            'credential' => $v->credential,
            'health_score' => $v->healthScore,
            'state' => $v->accessState,
            'telegram_usable' => $v->telegramUsable,
            'country' => $v->country,
        ], $views);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function fileExtension(): string
    {
        return 'json';
    }
}
