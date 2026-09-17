<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

/**
 * Telegram URI formatter — generates `tg://proxy?...` URIs from MTProto
 * endpoint+credential. NEVER stores the URI; generated on-the-fly during export.
 *
 * @see https://core.telegram.org/mtproto/mtproto-proxy#connecting-to-a-proxy
 */
final class TelegramProxyUriFormatter implements ExportFormatter
{
    public function format(array $views): string
    {
        $uris = [];

        foreach ($views as $view) {
            if ($view->credential === '' || $view->credential === null) {
                continue;
            }

            $uris[] = TelegramProxyUriBuilder::build(
                host: $view->host,
                port: $view->port,
                secret: $view->credential,
            );
        }

        return implode("\n", $uris).($uris !== [] ? "\n" : '');
    }

    public function mimeType(): string
    {
        return 'text/plain';
    }

    public function fileExtension(): string
    {
        return 'txt';
    }
}
