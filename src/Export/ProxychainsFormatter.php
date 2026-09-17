<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Generates proxychains.conf snippet (plan §11.28).
 */
final class ProxychainsFormatter implements ExportFormatter
{
    /**
     * @param  list<ExportView>  $views
     */
    public function format(array $views): string
    {
        $lines = ['[ProxyList]'];

        foreach ($views as $view) {
            $type = match (true) {
                str_contains($view->protocol->value, 'socks5') => 'socks5',
                str_contains($view->protocol->value, 'socks4') => 'socks4',
                default => 'http',
            };

            $line = "{$type} {$view->host} {$view->port}";

            if ($view->credential !== '') {
                $parts = explode(':', $view->credential, 2);
                $line .= ' '.($parts[0] ?? '').' '.($parts[1] ?? '');
            }

            $lines[] = $line;
        }

        return implode("\n", $lines)."\n";
    }

    public function mimeType(): string
    {
        return 'text/plain';
    }

    public function fileExtension(): string
    {
        return 'conf';
    }
}
