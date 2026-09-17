<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Generates Clash-compatible YAML (plan §11.28).
 */
final class ClashFormatter implements ExportFormatter
{
    /**
     * @param  list<ExportView>  $views
     */
    public function format(array $views): string
    {
        $lines = ['proxies:'];
        $idx = 0;

        foreach ($views as $view) {
            $idx++;
            $name = "proxy-{$idx}";
            $type = match (true) {
                str_contains($view->protocol->value, 'socks5') => 'socks5',
                str_contains($view->protocol->value, 'socks4') => 'socks4',
                default => 'http',
            };

            $lines[] = "  - name: \"{$name}\"";
            $lines[] = "    type: \"{$type}\"";
            $lines[] = "    server: \"{$view->host}\"";
            $lines[] = "    port: {$view->port}";

            if ($view->credential !== '') {
                $parts = explode(':', $view->credential, 2);
                if (count($parts) === 2) {
                    $lines[] = "    username: \"{$parts[0]}\"";
                    $lines[] = "    password: \"{$parts[1]}\"";
                }
            }
        }

        return implode("\n", $lines)."\n";
    }

    public function mimeType(): string
    {
        return 'text/yaml';
    }

    public function fileExtension(): string
    {
        return 'yaml';
    }
}
