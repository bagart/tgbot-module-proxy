<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Export\ExportView;
use BAGArt\ProxyOperations\Export\TelegramProxyUriBuilder;
use BAGArt\ProxyOperations\Export\TelegramProxyUriFormatter;
use BAGArt\ProxyOperations\Export\TelegramReadyFilter;

function makeTgView(string $accessId = 'acc-1', ?bool $tgUsable = true, ProxyProtocol $proto = ProxyProtocol::Socks5): ExportView
{
    return new ExportView(
        accessId: $accessId,
        protocol: $proto,
        host: '149.154.167.51',
        port: 443,
        credential: 'aabbccdd11223344',
        healthScore: 0.9,
        accessState: 'WORKING',
        telegramUsable: $tgUsable,
        country: 'NL',
    );
}

it('builds standard tg://proxy URI', function (): void {
    $uri = TelegramProxyUriBuilder::build('149.154.167.51', 443, 'aabbccdd11223344');

    expect($uri)->toContain('tg://proxy?')
        ->and($uri)->toContain('server=149.154.167.51')
        ->and($uri)->toContain('port=443')
        ->and($uri)->toContain('secret=aabbccdd11223344');
});

it('builds FakeTLS URI with domain hex prefix', function (): void {
    $uri = TelegramProxyUriBuilder::buildFakeTls('149.154.167.51', 443, 'example.com', 'aabbccdd');

    expect($uri)->toContain('secret=ee')
        ->and($uri)->toContain(bin2hex('example.com'));
});

it('builds Restricted URI with +r prefix', function (): void {
    $uri = TelegramProxyUriBuilder::buildRestricted('149.154.167.51', 443, 'short123');

    expect($uri)->toContain('secret=%2Brshort123');
});

it('wraps IPv6 hosts in brackets', function (): void {
    $uri = TelegramProxyUriBuilder::build('2001:db8::1', 443, 'aabb');

    expect($uri)->toContain('[');
});

it('formats multiple views as newline-separated URIs', function (): void {
    $formatter = new TelegramProxyUriFormatter();
    $result = $formatter->format([
        makeTgView('a'),
        makeTgView('b'),
    ]);

    $lines = array_filter(explode("\n", $result));
    expect($lines)->toHaveCount(2);
});

it('skips views with empty credential', function (): void {
    $formatter = new TelegramProxyUriFormatter();
    $view = new ExportView(
        accessId: 'x',
        protocol: ProxyProtocol::Mtproto,
        host: '1.2.3.4',
        port: 443,
        credential: '',
        healthScore: null,
        accessState: 'NEW',
        telegramUsable: null,
        country: null,
    );

    expect($formatter->format([$view]))->toBeEmpty();
});

it('returns correct mime type and extension', function (): void {
    $f = new TelegramProxyUriFormatter();
    expect($f->mimeType())->toBe('text/plain')
        ->and($f->fileExtension())->toBe('txt');
});

it('TelegramReadyFilter selects only TG-usable views', function (): void {
    $filter = new TelegramReadyFilter();
    $views = [
        makeTgView('a', tgUsable: true),
        makeTgView('b', tgUsable: false),
        makeTgView('c', tgUsable: true, proto: ProxyProtocol::Mtproto),
        makeTgView('d', tgUsable: true, proto: ProxyProtocol::Http),
    ];

    $filtered = $filter->filter($views);

    expect($filtered)->toHaveCount(3)
        ->and(array_map(fn (ExportView $v) => $v->accessId, $filtered))->not->toContain('b');
});

it('TelegramReadyFilter excludes non-TG protocols even if usable', function (): void {
    $filter = new TelegramReadyFilter();

    // Socks4 is not TG-compatible
    $view = makeTgView('x', tgUsable: true, proto: ProxyProtocol::Socks4);
    expect($filter->filter([$view]))->toHaveCount(0);
});
