<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Domain\Parsing\ParseErrorCode;
use BAGArt\ProxyOperations\Domain\Parsing\ProxyListParser;

it('parses socks5 with user:pass credentials', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://admin:secret@1.2.3.4:1080');

    expect($result->parsedCount)->toBe(1)
        ->and($result->errorCount)->toBe(0);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('socks5')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(1080)
        ->and($entry->username)->toBe('admin')
        ->and($entry->secret)->toBe('secret')
        ->and($entry->credentialKind)->toBe(CredentialKind::SocksAuth);
});

it('parses socks4 bare host:port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks4://1.2.3.4:1080');

    expect($result->parsedCount)->toBe(1);
    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('socks4')
        ->and($entry->port)->toBe(1080)
        ->and($entry->username)->toBeNull()
        ->and($entry->secret)->toBeNull();
});

it('parses http with basic auth', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('http://user:pass@1.2.3.4:8080');

    expect($result->parsedCount)->toBe(1);
    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('http')
        ->and($entry->credentialKind)->toBe(CredentialKind::BasicAuth);
});

it('parses https with basic auth', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('https://user:pass@1.2.3.4:8443');

    expect($result->parsedCount)->toBe(1);
    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('https')
        ->and($entry->credentialKind)->toBe(CredentialKind::BasicAuth);
});

it('parses bare host:port as socks5', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('1.2.3.4:1080');

    expect($result->parsedCount)->toBe(1);
    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('socks5')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(1080);
});

it('parses user:pass@host:port without scheme as socks5', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('admin:secret@1.2.3.4:1080');

    expect($result->parsedCount)->toBe(1);
    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('socks5')
        ->and($entry->username)->toBe('admin')
        ->and($entry->secret)->toBe('secret');
});

it('assigns default port per scheme', function (): void {
    $parser = new ProxyListParser();

    $socks = $parser->parse('1.2.3.4:1080');
    expect($socks->entries[0]->port)->toBe(1080);

    $http = $parser->parse('http://1.2.3.4:80');
    expect($http->entries[0]->port)->toBe(80);

    $https = $parser->parse('https://1.2.3.4:443');
    expect($https->entries[0]->port)->toBe(443);
});

it('parses IPv6 with brackets in bare format', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('[::1]:1080');

    expect($result->parsedCount)->toBe(1);
    $entry = $result->entries[0];
    expect($entry->host)->toBe('::1')
        ->and($entry->port)->toBe(1080);
});

it('parses IPv6 with brackets in scheme format', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://[::1]:1080');

    expect($result->parsedCount)->toBe(1);
    $entry = $result->entries[0];
    expect($entry->host)->toBe('::1')
        ->and($entry->port)->toBe(1080);
});

it('expands CIDR within limit', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('10.0.0.0/30');

    expect($result->parsedCount)->toBe(4)
        ->and($result->errorCount)->toBe(0);

    $ips = array_map(fn ($e) => $e->host, $result->entries);
    expect($ips)->toBe(['10.0.0.0', '10.0.0.1', '10.0.0.2', '10.0.0.3']);
});

it('tracks line numbers correctly in errors', function (): void {
    $parser = new ProxyListParser();
    $text = "1.2.3.4:1080\nvless://bad@host:443\n1.2.3.5:1080";
    $result = $parser->parse($text);

    expect($result->parsedCount)->toBe(2)
        ->and($result->errorCount)->toBe(1);

    $error = $result->errors[0];
    expect($error->line)->toBe(2)
        ->and($error->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('parses socks5h protocol', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5h://1.2.3.4:1080');

    expect($result->parsedCount)->toBe(1);
    expect($result->entries[0]->scheme)->toBe('socks5h');
});

it('parses socks4a protocol', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks4a://1.2.3.4:1080');

    expect($result->parsedCount)->toBe(1);
    expect($result->entries[0]->scheme)->toBe('socks4a');
});

it('parses mtproto with valid hex secret', function (): void {
    $parser = new ProxyListParser();
    $secret = bin2hex(random_bytes(16));
    $result = $parser->parse("mtproto://{$secret}@1.2.3.4:443");

    expect($result->parsedCount)->toBe(1);
    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('mtproto')
        ->and($entry->credentialKind)->toBe(CredentialKind::MtprotoSecret)
        ->and($entry->secret)->toBe($secret);
});
