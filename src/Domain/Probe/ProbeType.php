<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Domain\Probe;

/**
 * Probe type identifiers executed by probe runners (plan §11.39 п.5).
 */
enum ProbeType: string
{
    case HttpLiveness = 'http_liveness';
    case LatencySeries = 'latency_series';
    case HeaderMarker = 'header_marker';
    case AnonymityHeaders = 'anonymity_headers';
    case UdpAssociate = 'udp_associate';
    case DnsResolution = 'dns_resolution';
    case TelegramDcConnectivity = 'telegram_dc_connectivity';
    case MtprotoHandshake = 'mtproto_handshake';
    case BandwidthTransfer = 'bandwidth_transfer';
}
