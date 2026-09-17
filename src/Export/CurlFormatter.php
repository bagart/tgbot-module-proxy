<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Generates curl -x command snippets (plan §11.28).
 */
final class CurlFormatter implements ExportFormatter
{
    /**
     * @param  list<ExportView>  $views
     */
    public function format(array $views): string
    {
        $lines = [];

        foreach ($views as $view) {
            $scheme = match (true) {
                str_contains($view->protocol->value, 'socks5') => 'socks5h',
                str_contains($view->protocol->value, 'socks4') => 'socks4',
                default => $view->protocol->value,
            };

            $proxy = "{$scheme}://";
            if ($view->credential !== '') {
                $proxy .= rawurlencode($view->credential).'@';
            }
            $proxy .= "{$view->host}:{$view->port}";

            $lines[] = "curl -x '{$proxy}' http://example.com";
        }

        return implode("\n", $lines).($lines !== [] ? "\n" : '');
    }

    public function mimeType(): string
    {
        return 'text/plain';
    }

    public function fileExtension(): string
    {
        return 'sh';
    }
}
