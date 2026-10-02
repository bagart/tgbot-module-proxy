<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * TXT export formatter — three variants for different use cases.
 */
final class TxtExportFormatter implements ExportFormatter
{
    public function __construct(
        private string $variant = 'host_port',
    ) {
    }

    /**
     * @param  list<ExportView>  $views
     */
    public function format(array $views): string
    {
        $lines = [];

        foreach ($views as $view) {
            $lines[] = match ($this->variant) {
                'scheme_user_pass' => $view->credential !== ''
                    ? "{$this->scheme($view)}://{$view->credential}@{$view->host}:{$view->port}"
                    : "{$this->scheme($view)}://{$view->host}:{$view->port}",
                'host_port_user_pass' => "{$view->host}:{$view->port}:{$view->credential}",
                default => "{$view->host}:{$view->port}",
            };
        }

        return implode("\n", $lines).($lines !== [] ? "\n" : '');
    }

    public function mimeType(): string
    {
        return 'text/plain';
    }

    public function fileExtension(): string
    {
        return 'txt';
    }

    private function scheme(ExportView $view): string
    {
        return match (true) {
            str_contains($view->protocol->value, 'socks') => 'socks5',
            default => $view->protocol->value,
        };
    }
}
