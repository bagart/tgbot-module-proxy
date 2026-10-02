<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\CredentialKind;
use BAGArt\ProxyOperations\Domain\Parsing\ParsedEntry;
use BAGArt\ProxyOperations\Domain\Parsing\ParseError;
use BAGArt\ProxyOperations\Domain\Parsing\ParseErrorCode;
use BAGArt\ProxyOperations\Domain\Parsing\ParseResult;
use BAGArt\ProxyOperations\Domain\Parsing\ProxyListParser;

it('parses socks5 with user:pass', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://user:pass@1.2.3.4:1080');

    expect($result->entries)->toHaveCount(1)
        ->and($result->errors)->toHaveCount(0)
        ->and($result->parsedCount)->toBe(1)
        ->and($result->errorCount)->toBe(0);

    $entry = $result->entries[0];
    expect($entry)->toBeInstanceOf(ParsedEntry::class)
        ->and($entry->scheme)->toBe('socks5')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(1080)
        ->and($entry->username)->toBe('user')
        ->and($entry->secret)->toBe('pass')
        ->and($entry->credentialKind)->toBe(CredentialKind::SocksAuth)
        ->and($entry->sourceLine)->toBe(1);
});

it('parses http without credentials', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('http://proxy.example.com:8080');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('http')
        ->and($entry->host)->toBe('proxy.example.com')
        ->and($entry->port)->toBe(8080)
        ->and($entry->username)->toBeNull()
        ->and($entry->secret)->toBeNull()
        ->and($entry->credentialKind)->toBe(CredentialKind::SocksAuth);
});

it('parses bare host:port as socks5', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('1.2.3.4:1080');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('socks5')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(1080)
        ->and($entry->username)->toBeNull();
});

it('parses user:pass@host:port without scheme', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('user:pass@1.2.3.4:1080');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('socks5')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(1080)
        ->and($entry->username)->toBe('user')
        ->and($entry->secret)->toBe('pass')
        ->and($entry->credentialKind)->toBe(CredentialKind::SocksAuth);
});

it('parses host:port:user:pass format', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('1.2.3.4:1080:user:pass');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('socks5')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(1080)
        ->and($entry->username)->toBe('user')
        ->and($entry->secret)->toBe('pass')
        ->and($entry->credentialKind)->toBe(CredentialKind::SocksAuth);
});

it('parses space-separated host port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('1.2.3.4 1080');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('socks5')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(1080);
});

it('parses mtproto scheme format', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('mtproto://aabbccdd11223344aabbccdd11223344@1.2.3.4:443');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('mtproto')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(443)
        ->and($entry->username)->toBeNull()
        ->and($entry->secret)->toBe('aabbccdd11223344aabbccdd11223344')
        ->and($entry->credentialKind)->toBe(CredentialKind::MtprotoSecret);
});

it('parses tg://proxy? query format', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('tg://proxy?server=1.2.3.4&port=443&secret=aabbccdd11223344aabbccdd11223344');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('mtproto')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(443)
        ->and($entry->secret)->toBe('aabbccdd11223344aabbccdd11223344')
        ->and($entry->credentialKind)->toBe(CredentialKind::MtprotoSecret);
});

it('parses https://t.me/proxy? query format', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('https://t.me/proxy?server=1.2.3.4&port=443&secret=aabbccdd11223344aabbccdd11223344');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->scheme)->toBe('mtproto')
        ->and($entry->host)->toBe('1.2.3.4')
        ->and($entry->port)->toBe(443)
        ->and($entry->secret)->toBe('aabbccdd11223344aabbccdd11223344')
        ->and($entry->credentialKind)->toBe(CredentialKind::MtprotoSecret);
});

it('expands CIDR /30 into 4 entries', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('10.0.0.0/30');

    expect($result->entries)->toHaveCount(4)
        ->and($result->errors)->toHaveCount(0);

    $hosts = array_map(fn (ParsedEntry $e) => $e->host, $result->entries);
    expect($hosts)->toBe(['10.0.0.0', '10.0.0.1', '10.0.0.2', '10.0.0.3']);

    foreach ($result->entries as $entry) {
        expect($entry->scheme)->toBe('socks5')
            ->and($entry->port)->toBe(1080)
            ->and($entry->originalHost)->toBe('10.0.0.0/30');
    }
});

it('parses IPv6 host in brackets', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('[::1]:8080');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->host)->toBe('::1')
        ->and($entry->port)->toBe(8080);
});

it('parses IPv6 with scheme', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://[2001:db8::1]:1080');

    expect($result->entries)->toHaveCount(1);

    $entry = $result->entries[0];
    expect($entry->host)->toBe('2001:db8::1')
        ->and($entry->port)->toBe(1080)
        ->and($entry->scheme)->toBe('socks5');
});

it('skips comment lines', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("# this is a comment\n// another comment\n1.2.3.4:1080");

    expect($result->entries)->toHaveCount(1)
        ->and($result->errors)->toHaveCount(2)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::CommentLine)
        ->and($result->errors[0]->line)->toBe(1)
        ->and($result->errors[1]->code)->toBe(ParseErrorCode::CommentLine)
        ->and($result->errors[1]->line)->toBe(2);

    expect($result->entries[0]->host)->toBe('1.2.3.4');
});

it('skips empty lines', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("\n\n1.2.3.4:1080\n\n");

    expect($result->entries)->toHaveCount(1)
        ->and($result->errors)->toHaveCount(4)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::EmptyLine)
        ->and($result->errors[0]->line)->toBe(1)
        ->and($result->errors[1]->code)->toBe(ParseErrorCode::EmptyLine)
        ->and($result->errors[1]->line)->toBe(2);
});

it('rejects vless with NonVpnRejected error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('vless://user:pass@host:port');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected)
        ->and($result->errors[0]->line)->toBe(1)
        ->and($result->errors[0]->detail)->toContain('vless');
});

it('rejects vmess with NonVpnRejected error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('vmess://some-data');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects trojan with NonVpnRejected error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('trojan://user:pass@host:443');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects ss with NonVpnRejected error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('ss://some-data');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects wireguard with NonVpnRejected error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('wireguard://config-data');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects openvpn with NonVpnRejected error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('openvpn://config-data');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected);
});

it('rejects VPN schemes case-insensitively', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("VLESS://data\nTrojan://data\nSS://data");

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors)->toHaveCount(3);

    foreach ($result->errors as $error) {
        expect($error->code)->toBe(ParseErrorCode::NonVpnRejected);
    }
});

it('rejects out-of-range port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://host:99999');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidPortRange);
});

it('rejects port zero', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://host:0');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidPortRange);
});

it('rejects empty host with scheme', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://:1080');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidFormat);
});

it('rejects malformed scheme URL', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('://host:1080');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidFormat);
});

it('rejects CIDR exceeding max expansion', function (): void {
    $parser = new ProxyListParser(cidrMaxExpansion: 10);
    $result = $parser->parse('10.0.0.0/24');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::CidrExpansionFailed)
        ->and($result->errors[0]->detail)->toContain('256')
        ->and($result->errors[0]->detail)->toContain('10');
});

it('rejects non-hex MTProto secret', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('mtproto://xyz@host:443');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidMtprotoSecret)
        ->and($result->errors[0]->detail)->toContain('non-hex');
});

it('rejects MTProto secret too short', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('mtproto://abc@host:443');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidMtprotoSecret)
        ->and($result->errors[0]->detail)->toContain('too short');
});

it('rejects bare IPv6 without brackets', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('::1:8080');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors)->not->toBeEmpty();

    $formatErrors = array_filter(
        $result->errors,
        static fn (ParseError $e) => $e->code === ParseErrorCode::InvalidFormat,
    );
    expect($formatErrors)->not->toBeEmpty();
});

it('rejects unsupported scheme', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('ftp://host:21');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::UnsupportedScheme)
        ->and($result->errors[0]->detail)->toContain('ftp');
});

it('validates ParseResult counters are consistent', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("socks5://u:p@1.2.3.4:1080\n# comment\nvless://data\n\nhttp://h:80");

    expect($result->totalLines)->toBe(5)
        ->and($result->parsedCount)->toBe(count($result->entries))
        ->and($result->errorCount)->toBe(count($result->errors))
        ->and($result->parsedCount + $result->errorCount)->toBe($result->totalLines);
});

it('serializes ParsedEntry without secret key', function (): void {
    $entry = new ParsedEntry(
        scheme: 'socks5',
        host: '1.2.3.4',
        port: 1080,
        username: 'user',
        secret: 'my-secret-password',
        credentialKind: CredentialKind::SocksAuth,
        sourceLine: 1,
        originalHost: '1.2.3.4',
    );

    $json = $entry->jsonSerialize();
    expect($json)->not->toHaveKey('secret')
        ->and($json)->toHaveKey('scheme')
        ->and($json)->toHaveKey('host')
        ->and($json)->toHaveKey('port')
        ->and($json)->toHaveKey('username')
        ->and($json)->toHaveKey('credentialKind')
        ->and($json)->toHaveKey('sourceLine')
        ->and($json)->toHaveKey('schemaVersion')
        ->and($json['schemaVersion'])->toBe(1);
});

it('serializes MTProto ParsedEntry without secret', function (): void {
    $entry = new ParsedEntry(
        scheme: 'mtproto',
        host: '1.2.3.4',
        port: 443,
        username: null,
        secret: 'abc123def456',
        credentialKind: CredentialKind::MtprotoSecret,
        sourceLine: 1,
        originalHost: '1.2.3.4',
    );

    $json = $entry->jsonSerialize();
    expect($json)->not->toHaveKey('secret')
        ->and($json['credentialKind'])->toBe('mtproto_secret');
});

it('serializes ParseError correctly', function (): void {
    $error = new ParseError(
        line: 5,
        rawLine: 'bad line',
        code: ParseErrorCode::InvalidFormat,
        detail: 'Something went wrong',
    );

    $json = $error->jsonSerialize();
    expect($json)->toBe([
        'line' => 5,
        'rawLine' => 'bad line',
        'code' => 'invalid_format',
        'detail' => 'Something went wrong',
        'schemaVersion' => 1,
    ]);
});

it('parses MTProto with +r restricted prefix', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('mtproto://+raabbccdd11223344aabbccdd11223344@1.2.3.4:443');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->secret)->toBe('+raabbccdd11223344aabbccdd11223344')
        ->and($result->entries[0]->credentialKind)->toBe(CredentialKind::MtprotoSecret);
});

it('parses MTProto with FakeTLS (ee prefix, 34+ hex)', function (): void {
    $secret = 'ee'.str_repeat('ab', 17);
    $parser = new ProxyListParser();
    $result = $parser->parse("mtproto://{$secret}@1.2.3.4:443");

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->secret)->toBe($secret);
});

it('uses default port for scheme when missing', function (): void {
    $parser = new ProxyListParser();

    $result = $parser->parse('http://proxy.example.com');
    expect($result->errors)->not->toBeEmpty();

    $result = $parser->parse('socks5://1.2.3.4:1080');
    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->port)->toBe(1080);
});

it('parses multiple entries from multiline input', function (): void {
    $input = "# Proxy list\nsocks5://u:p@1.2.3.4:1080\nhttp://proxy.example.com:8080\n\n10.0.0.0/30\n";
    $parser = new ProxyListParser();
    $result = $parser->parse($input);

    expect($result->entries)->toHaveCount(6)
        ->and($result->errors)->toHaveCount(3)
        ->and($result->totalLines)->toBe(6)
        ->and($result->parsedCount)->toBe(6)
        ->and($result->errorCount)->toBe(3);
});

it('rejects host:port:user:pass with non-numeric port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('abc:notaport:user:pass');

    expect($result->entries)->toHaveCount(0);
    expect($result->errors)->not->toBeEmpty();
});

it('http with user:pass uses BasicAuth credential kind', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('http://user:pass@proxy.example.com:8080');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->credentialKind)->toBe(CredentialKind::BasicAuth)
        ->and($result->entries[0]->scheme)->toBe('http');
});

it('parses socks4a scheme', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks4a://1.2.3.4:1080');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->scheme)->toBe('socks4a');
});

it('parses socks5h scheme', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5h://1.2.3.4:1080');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->scheme)->toBe('socks5h');
});

it('parses CIDR with scheme and port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://10.0.0.0/30:9090');

    expect($result->entries)->toHaveCount(4)
        ->and($result->errors)->toHaveCount(0);

    foreach ($result->entries as $entry) {
        expect($entry->port)->toBe(9090)
            ->and($entry->scheme)->toBe('socks5');
    }
});

it('CIDR /31 produces 2 entries', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('192.168.1.0/31');

    expect($result->entries)->toHaveCount(2);
    $hosts = array_map(fn (ParsedEntry $e) => $e->host, $result->entries);
    expect($hosts)->toBe(['192.168.1.0', '192.168.1.1']);
});

it('CIDR /32 produces 1 entry', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('192.168.1.5/32');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->host)->toBe('192.168.1.5');
});

it('CIDR /0 produces error when exceeding limit', function (): void {
    $parser = new ProxyListParser(cidrMaxExpansion: 1024);
    $result = $parser->parse('0.0.0.0/0');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::CidrExpansionFailed);
});

it('rejects CIDR with invalid prefix', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('10.0.0.0/33');

    expect($result->entries)->toHaveCount(0);
    expect($result->errors)->not->toBeEmpty();
});

it('rejects CIDR with invalid base IP', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('not-an-ip/24');

    expect($result->entries)->toHaveCount(0);
    expect($result->errors)->not->toBeEmpty();
});

it('mtproto tg://proxy with IPv6 server', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('tg://proxy?server=2001:db8::1&port=443&secret=aabbccdd11223344aabbccdd11223344');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->host)->toBe('2001:db8::1');
});

it('mtproto tg://proxy missing params returns error', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('tg://proxy?server=1.2.3.4');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidFormat);
});

it('mtproto https://t.me/proxy? with missing secret', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('https://t.me/proxy?server=1.2.3.4&port=443');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidFormat);
});

it('non-recognized scheme returns UnsupportedScheme', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('telnet://host:23');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::UnsupportedScheme);
});

it('rawLine in ParseError is truncated to 200 chars', function (): void {
    $longLine = str_repeat('x', 300);
    $parser = new ProxyListParser();
    $result = $parser->parse($longLine);

    expect($result->errors)->not->toBeEmpty()
        ->and(strlen($result->errors[0]->rawLine))->toBeLessThanOrEqual(200);
});

it('fromJson roundtrip for ParseError', function (): void {
    $error = new ParseError(
        line: 3,
        rawLine: 'bad line',
        code: ParseErrorCode::InvalidFormat,
        detail: 'Detail text',
    );

    $json = $error->jsonSerialize();
    $restored = ParseError::fromJson($json);

    expect($restored->line)->toBe(3)
        ->and($restored->rawLine)->toBe('bad line')
        ->and($restored->code)->toBe(ParseErrorCode::InvalidFormat)
        ->and($restored->detail)->toBe('Detail text');
});

it('fromJson roundtrip for ParsedEntry omits secret', function (): void {
    $entry = new ParsedEntry(
        scheme: 'socks5',
        host: '1.2.3.4',
        port: 1080,
        username: 'user',
        secret: 'secret-password',
        credentialKind: CredentialKind::SocksAuth,
        sourceLine: 1,
        originalHost: '1.2.3.4',
    );

    $json = $entry->jsonSerialize();
    $restored = ParsedEntry::fromJson($json);

    expect($restored->host)->toBe('1.2.3.4')
        ->and($restored->secret)->toBeNull()
        ->and($restored->scheme)->toBe('socks5')
        ->and($restored->port)->toBe(1080)
        ->and($restored->username)->toBe('user');
});

it('fromJson roundtrip for ParseResult', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("socks5://u:p@1.2.3.4:1080\n# comment\n");

    $json = $result->jsonSerialize();
    $restored = ParseResult::fromJson($json);

    expect($restored->totalLines)->toBe(3)
        ->and($restored->parsedCount)->toBe(1)
        ->and($restored->errorCount)->toBe(2)
        ->and($restored->entries)->toHaveCount(1)
        ->and($restored->errors)->toHaveCount(2);
});

it('empty input produces empty result', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors)->toHaveCount(0)
        ->and($result->totalLines)->toBe(0)
        ->and($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(0);
});

it('all-comment input produces errors only', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("# comment 1\n# comment 2");

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors)->toHaveCount(2)
        ->and($result->parsedCount)->toBe(0)
        ->and($result->errorCount)->toBe(2);
});

it('rejects scheme with empty host:port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://');

    expect($result->entries)->toHaveCount(0);
    expect($result->errors)->not->toBeEmpty();
});

it('accepts https scheme with user:pass', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('https://admin:secret@secure.proxy.com:443');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->scheme)->toBe('https')
        ->and($result->entries[0]->credentialKind)->toBe(CredentialKind::BasicAuth)
        ->and($result->entries[0]->username)->toBe('admin');
});

it('non-VPN guard triggers before scheme detection', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('vless://user:pass@1.2.3.4:443');

    expect($result->errors[0]->code)->toBe(ParseErrorCode::NonVpnRejected)
        ->and($result->errors[0]->detail)->toContain('vless');
});

it('password in jsonSerialize is excluded', function (): void {
    $entry = new ParsedEntry(
        scheme: 'socks5',
        host: '1.2.3.4',
        port: 1080,
        username: 'user',
        secret: 'password123',
        credentialKind: CredentialKind::SocksAuth,
        sourceLine: 1,
        originalHost: '1.2.3.4',
    );

    $serialized = json_encode($entry);
    expect($serialized)->not->toContain('password123')
        ->and($serialized)->not->toContain('secret');
});

it('socks4 scheme is accepted', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks4://1.2.3.4:1080');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->scheme)->toBe('socks4')
        ->and($result->entries[0]->credentialKind)->toBe(CredentialKind::SocksAuth);
});

it('IPv6 host with scheme and credentials', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://user:pass@[::1]:1080');

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->host)->toBe('::1')
        ->and($result->entries[0]->username)->toBe('user')
        ->and($result->entries[0]->port)->toBe(1080);
});

it('trims trailing whitespace from lines', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse("1.2.3.4:1080   \n");

    expect($result->entries)->toHaveCount(1)
        ->and($result->errors)->toHaveCount(1)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::EmptyLine);
});

it('rejects CIDR with prefix 33', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('10.0.0.0/33');

    expect($result->entries)->toHaveCount(0);
    expect($result->errors)->not->toBeEmpty();
});

it('rejects negative port', function (): void {
    $parser = new ProxyListParser();
    $result = $parser->parse('socks5://host:-1');

    expect($result->entries)->toHaveCount(0)
        ->and($result->errors[0]->code)->toBe(ParseErrorCode::InvalidPortRange);
});

it('mtproto with dd-padded secret (34 hex chars)', function (): void {
    $secret = 'dd'.str_repeat('ab', 16);
    $parser = new ProxyListParser();
    $result = $parser->parse("mtproto://{$secret}@1.2.3.4:443");

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->secret)->toBe($secret);
});
