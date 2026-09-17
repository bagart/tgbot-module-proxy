<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Export;

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

/**
 * Filter that selects accesses where telegram_usable = true
 * AND protocol supports TG connectivity.
 */
final class TelegramReadyFilter
{
    /**
     * @param  list<ExportView>  $views
     * @return list<ExportView>
     */
    public function filter(array $views): array
    {
        return array_values(array_filter(
            $views,
            static fn (ExportView $v): bool => $v->telegramUsable === true
                && in_array($v->protocol, [
                    ProxyProtocol::Socks5,
                    ProxyProtocol::Socks5h,
                    ProxyProtocol::Http,
                    ProxyProtocol::Https,
                    ProxyProtocol::Mtproto,
                ], true),
        ));
    }
}
