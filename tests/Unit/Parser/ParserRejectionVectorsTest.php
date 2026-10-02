<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Parsing\ParseErrorCode;
use BAGArt\ProxyOperations\Domain\Parsing\ProxyListParser;

it('rejects vless with NonVpnRejected', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('vless://secret@1.2.3.4:443');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects vmess with NonVpnRejected', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('vmess://secret@1.2.3.4:443');

    expect($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects trojan with NonVpnRejected', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('trojan://secret@1.2.3.4:443');

    expect($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects ss with NonVpnRejected', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('ss://secret@1.2.3.4:443');

    expect($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects wireguard with NonVpnRejected', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('wireguard://secret@1.2.3.4:443');

    expect($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects openvpn with NonVpnRejected', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('openvpn://secret@1.2.3.4:443');

    expect($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects port out of range', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('1.2.3.4:70000');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidPortRange);
});

it('rejects negative port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('1.2.3.4:-1');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1);
});

it('rejects non-numeric port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://1.2.3.4:abc');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidPort);
});

it('rejects missing host', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://:1080');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidFormat);
});

it('rejects invalid MTProto secret (non-hex)', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('mtproto://not-hex!@1.2.3.4:443');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidMtprotoSecret);
});

it('rejects MTProto secret that is too short', function (): void {
    $parser = new ProxyListParser();
    $shortSecret = bin2hex(random_bytes(8));
    $result = $parser->parse("mtproto://{$shortSecret}@1.2.3.4:443");

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidMtprotoSecret);
});

it('rejects CIDR expansion exceeding limit', function (): void {
    $parser = new ProxyListParser(10);
    $result = $parser->parse('10.0.0.0/24');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::CidrExpansionFailed);
});

it('rejects unsupported scheme', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('ftp://1.2.3.4:21');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::UnsupportedScheme);
});

it('rejects empty input', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(0)
        ->and($result->totalLines)->toBe(0);
});

it('reports empty lines as EmptyLine error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("1.2.3.4:1080\n\n1.2.3.5:1080");

    expect($result->parsedCount)->toBe(2)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::EmptyLine)
        ->and($result->errors[0]->line)->toBe(2);
});

it('reports comment lines as CommentLine error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("# this is a comment\n1.2.3.4:1080");

    expect($result->parsedCount)->toBe(1)
        ->and($result->errorCount)->toBe(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::CommentLine)
        ->and($result->errors[0]->line)->toBe(1);
});

it('rejects CIDR with invalid base IP', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('not-an-ip/24');

    expect($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(1);
});
