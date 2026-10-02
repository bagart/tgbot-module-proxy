<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\Adapters\Socks5UdpAdapter;
use BAGArt\ProxyOperations\Transport\Adapters\UdpAssociateResult;
use BAGArt\ProxyOperations\Transport\Adapters\UdpDatagramResult;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use BAGArt\ProxyOperations\Transport\TlsOptions;

it('rejects non-Socks5 scheme', function (): void {
    $adapter = new Socks5UdpAdapter();
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: 80,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $adapter->associate($config, '10.0.0.1', 53);
})->throws(InvalidArgumentException::class, 'Socks5UdpAdapter requires Socks5 or Socks5h scheme');

it('performs UDP ASSOCIATE through mock SOCKS5 proxy', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(enableUdpAssociate: true),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fread($client, 4);

        fwrite($client, "\x05\x00");

        fread($client, 20);

        fwrite($client, "\x05\x00\x00\x01\x7f\x00\x00\x01\x10\x68");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new Socks5UdpAdapter();
    $result = $adapter->associate($config, '10.0.0.1', 53);

    expect($result)->toBeInstanceOf(UdpAssociateResult::class);
    expect($result->supported)->toBeTrue();
    expect($result->relayHost)->toBe('127.0.0.1');
    expect($result->relayPort)->toBe(4200);
    expect($result->error)->toBeNull();
    expect($result->timingsMs)->not->toBeEmpty();

    pcntl_waitpid($pid, $status);
    fclose($server);
});

it('captures correct UDP ASSOCIATE request bytes', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fread($client, 4);

        fwrite($client, "\x05\x00");

        $captured = fread($client, 30);

        fwrite($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);

        file_put_contents('/tmp/udp_assoc.bin', $captured);
        exit(0);
    }

    $adapter = new Socks5UdpAdapter();
    $adapter->associate($config, '10.0.0.1', 53);
    $adapter->close();
    pcntl_waitpid($pid, $status);

    $request = file_get_contents('/tmp/udp_assoc.bin');
    @unlink('/tmp/udp_assoc.bin');

    expect(ord($request[0]))->toBe(0x05);
    expect(ord($request[1]))->toBe(0x03); // UDP ASSOCIATE cmd
    expect(ord($request[2]))->toBe(0x00);

    fclose($server);
});

it('returns supported=false on UDP ASSOCIATE rejection', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fread($client, 4);

        fwrite($client, "\x05\x00");

        fread($client, 20);

        fwrite($client, "\x05\x07\x00\x01\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new Socks5UdpAdapter();
    $result = $adapter->associate($config, '10.0.0.1', 53);

    expect($result->supported)->toBeFalse();
    expect($result->error)->toContain('command not supported');

    pcntl_waitpid($pid, $status);
    fclose($server);
});

it('returns supported=false on auth failure', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fread($client, 4);

        fwrite($client, "\x05\xFF");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new Socks5UdpAdapter();
    $result = $adapter->associate($config, '10.0.0.1', 53);

    expect($result->supported)->toBeFalse();
    expect($result->error)->toContain('rejected all authentication methods');

    pcntl_waitpid($pid, $status);
    fclose($server);
});

it('returns supported=false when proxy is unreachable', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: 19999,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(),
    );

    $adapter = new Socks5UdpAdapter();
    $result = $adapter->associate($config, '10.0.0.1', 53);

    expect($result->supported)->toBeFalse();
    expect($result->error)->toContain('Cannot connect to SOCKS5 proxy');
});

it('serializes UdpAssociateResult to JSON', function (): void {
    $result = new UdpAssociateResult(
        supported: true,
        relayHost: '1.2.3.4',
        relayPort: 1080,
        error: null,
        timingsMs: ['tcpConnect' => 1.5],
    );

    $json = json_encode($result);
    $data = json_decode($json, true);

    expect($data['supported'])->toBeTrue();
    expect($data['relayHost'])->toBe('1.2.3.4');
    expect($data['relayPort'])->toBe(1080);
    expect($data['error'])->toBeNull();
    expect($data['timingsMs']['tcpConnect'])->toBe(1.5);
    expect($data['schemaVersion'])->toBe(1);
});

it('serializes UdpDatagramResult to JSON', function (): void {
    $result = new UdpDatagramResult(
        success: true,
        response: 'test-data',
        error: null,
        latencyMs: 2.3,
    );

    $json = json_encode($result);
    $data = json_decode($json, true);

    expect($data['success'])->toBeTrue();
    expect($data['response'])->toBe('test-data');
    expect($data['error'])->toBeNull();
    expect($data['latencyMs'])->toBe(2.3);
    expect($data['schemaVersion'])->toBe(1);
});

it('close is idempotent', function (): void {
    $adapter = new Socks5UdpAdapter();

    $adapter->close();
    $adapter->close();

    expect(true)->toBeTrue();
});

it('sends domain atyp for SOCKS5h UDP ASSOCIATE', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5h,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fread($client, 4);

        fwrite($client, "\x05\x00");

        $captured = fread($client, 30);

        fwrite($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);

        file_put_contents('/tmp/udp_assoc_domain.bin', $captured);
        exit(0);
    }

    $adapter = new Socks5UdpAdapter();
    $adapter->associate($config, 'example.com', 53);
    pcntl_waitpid($pid, $status);

    $request = file_get_contents('/tmp/udp_assoc_domain.bin');
    @unlink('/tmp/udp_assoc_domain.bin');

    expect(ord($request[0]))->toBe(0x05);
    expect(ord($request[1]))->toBe(0x03);
    expect(ord($request[3]))->toBe(0x03); // atyp=domain

    fclose($server);
});
