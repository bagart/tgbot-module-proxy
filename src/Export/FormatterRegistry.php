<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

use InvalidArgumentException;

/**
 * Maps format string → ExportFormatter instance.
 */
final class FormatterRegistry
{
    /** @var array<string, ExportFormatter> */
    private array $formatters;

    public function __construct()
    {
        $this->formatters = [
            'json' => new JsonExportFormatter(),
            'csv' => new CsvExportFormatter(),
            'txt' => new TxtExportFormatter('host_port'),
            'txt_scheme' => new TxtExportFormatter('scheme_user_pass'),
            'txt_full' => new TxtExportFormatter('host_port_user_pass'),
            'tg' => new TelegramProxyUriFormatter(),
            'proxychains' => new ProxychainsFormatter(),
            'curl' => new CurlFormatter(),
            'clash' => new ClashFormatter(),
        ];
    }

    public function get(string $format, ?string $variant = null): ExportFormatter
    {
        $key = $variant !== null ? "{$format}_{$variant}" : $format;

        if (! isset($this->formatters[$key])) {
            throw new InvalidArgumentException("Unknown export format: {$key}");
        }

        return $this->formatters[$key];
    }
}
