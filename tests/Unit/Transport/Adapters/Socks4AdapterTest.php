<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Transport\Adapters\Socks4Adapter;
use BAGArt\ProxyOperations\Transport\Adapters\TransportAuthException;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\ProxyCredentialRef;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use BAGArt\ProxyOperations\Transport\TlsOptions;

it('rejects non-Socks4 scheme', function (): void {
    $adapter = new Socks4Adapter();
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(),
    );

    $adapter->connect($config, 'example.com', 80);
})->throws(InvalidArgumentException::class, 'Socks4Adapter requires Socks4 or Socks4a scheme');

it('connects through mock SOCKS4 proxy with 0x5A response', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks4,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fread($client, 10);

        fwrite($client, "\x00\x5A\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new Socks4Adapter();
    $stream = $adapter->connect($config, '10.0.0.1', 80);

    expect($stream)->not->toBeFalse();
    expect(is_resource($stream))->toBeTrue();

    $adapter->close();
    pcntl_waitpid($pid, $status);
    fclose($server);
});

it('sends correct SOCKS4 handshake bytes for IP target', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks4,
        host: '127.0.0.1',
        port: $port,
        credential: new ProxyCredentialRef(
            username: 'testuser',
            channel: new StdinChannel(),
        ),
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        $capturedHandshake = fread($client, 64);

        fwrite($client, "\x00\x5A\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);

        file_put_contents('/tmp/socks4_handshake.bin', $capturedHandshake);
        exit(0);
    }

    $adapter = new Socks4Adapter();
    $adapter->connect($config, '93.184.216.34', 8080);
    $adapter->close();
    pcntl_waitpid($pid, $status);

    $handshake = file_get_contents('/tmp/socks4_handshake.bin');
    @unlink('/tmp/socks4_handshake.bin');

    expect(ord($handshake[0]))->toBe(0x01);
    expect(ord($handshake[1]))->toBe(0x01);
    expect(unpack('n', substr($handshake, 2, 2))[1])->toBe(8080);
    expect(ord($handshake[4]))->toBe(93);
    expect(ord($handshake[5]))->toBe(184);
    expect(ord($handshake[6]))->toBe(216);
    expect(ord($handshake[7]))->toBe(34);

    $userIdEnd = strpos($handshake, "\x00", 8);
    $userId = substr($handshake, 8, $userIdEnd - 8);
    expect($userId)->toBe('testuser');

    fclose($server);
});

it('sends SOCKS4a 0.0.0.1 + domain for domain targets', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks4a,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        $capturedHandshake = fread($client, 64);

        fwrite($client, "\x00\x5A\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);

        file_put_contents('/tmp/socks4a_handshake.bin', $capturedHandshake);
        exit(0);
    }

    $adapter = new Socks4Adapter();
    $adapter->connect($config, 'example.com', 443);
    $adapter->close();
    pcntl_waitpid($pid, $status);

    $handshake = file_get_contents('/tmp/socks4a_handshake.bin');
    @unlink('/tmp/socks4a_handshake.bin');

    expect(ord($handshake[0]))->toBe(0x01);
    expect(ord($handshake[1]))->toBe(0x01);
    expect(unpack('n', substr($handshake, 2, 2))[1])->toBe(443);

    expect(ord($handshake[4]))->toBe(0);
    expect(ord($handshake[5]))->toBe(0);
    expect(ord($handshake[6]))->toBe(0);
    expect(ord($handshake[7]))->toBe(1);

    $userIdEnd = strpos($handshake, "\x00", 8);
    $afterUserId = substr($handshake, $userIdEnd + 1);
    $domainEnd = strpos($afterUserId, "\x00");
    $domain = substr($afterUserId, 0, $domainEnd);

    expect($domain)->toBe('example.com');

    fclose($server);
});

it('throws TransportAuthException on SOCKS4 rejected user ID', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks4,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fread($client, 10);

        fwrite($client, "\x00\x5D\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new Socks4Adapter();
    $adapter->connect($config, '10.0.0.1', 80);
})->throws(TransportAuthException::class, 'SOCKS4 user ID mismatch');

it('throws TransportConnectionException on SOCKS4 general failure', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks4,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fread($client, 10);

        fwrite($client, "\x00\x5B\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new Socks4Adapter();
    $adapter->connect($config, '10.0.0.1', 80);
})->throws(TransportConnectionException::class, 'SOCKS4 request rejected or failed');

it('throws TransportConnectionException when proxy is unreachable', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks4,
        host: '127.0.0.1',
        port: 19999,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $adapter = new Socks4Adapter();
    $adapter->connect($config, '10.0.0.1', 80);
})->throws(TransportConnectionException::class, 'Cannot connect to SOCKS4 proxy');

it('close is idempotent', function (): void {
    $adapter = new Socks4Adapter();

    $adapter->close();
    $adapter->close();

    expect(true)->toBeTrue();
});
