<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Transport\Adapters\Socks5Adapter;
use BAGArt\ProxyOperations\Transport\Adapters\TransportAuthException;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\ProxyCredentialRef;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use BAGArt\ProxyOperations\Transport\TlsOptions;

it('rejects non-Socks5 scheme', function (): void {
    $adapter = new Socks5Adapter();
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: 80,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new HttpConnectOptions(),
    );

    $adapter->connect($config, 'example.com', 80);
})->throws(InvalidArgumentException::class, 'Socks5Adapter requires Socks5 or Socks5h scheme');

it('connects through mock SOCKS5 proxy with no-auth method', function (): void {
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

        fread($client, 10);

        fwrite($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new Socks5Adapter();
    $stream = $adapter->connect($config, '10.0.0.1', 80);

    expect($stream)->not->toBeFalse();
    expect(is_resource($stream))->toBeTrue();

    $adapter->close();
    pcntl_waitpid($pid, $status);
    fclose($server);
});

it('sends correct SOCKS5 greeting with no-auth and user/pass methods', function (): void {
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

        $capturedGreeting = fread($client, 10);

        fwrite($client, "\x05\x00");

        fread($client, 10);

        fwrite($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);

        file_put_contents('/tmp/socks5_greeting.bin', $capturedGreeting);
        exit(0);
    }

    $adapter = new Socks5Adapter();
    $adapter->connect($config, '10.0.0.1', 80);
    $adapter->close();
    pcntl_waitpid($pid, $status);

    $greeting = file_get_contents('/tmp/socks5_greeting.bin');
    @unlink('/tmp/socks5_greeting.bin');

    expect(ord($greeting[0]))->toBe(0x05);
    expect(ord($greeting[1]))->toBe(2);
    expect(ord($greeting[2]))->toBe(0x00);
    expect(ord($greeting[3]))->toBe(0x02);

    fclose($server);
});

it('performs user/pass sub-negotiation when proxy selects method 0x02', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: $port,
        credential: new ProxyCredentialRef(
            username: 'admin',
            channel: new StdinChannel(),
        ),
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

        fwrite($client, "\x05\x02");

        $capturedAuth = fread($client, 20);

        fwrite($client, "\x01\x00");

        fread($client, 10);

        fwrite($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);

        file_put_contents('/tmp/socks5_auth.bin', $capturedAuth);
        exit(0);
    }

    $adapter = new Socks5Adapter();
    $adapter->connect($config, '10.0.0.1', 80);
    $adapter->close();
    pcntl_waitpid($pid, $status);

    $auth = file_get_contents('/tmp/socks5_auth.bin');
    @unlink('/tmp/socks5_auth.bin');

    expect(ord($auth[0]))->toBe(0x01);
    expect(ord($auth[1]))->toBe(strlen('proxy'));
    expect(substr($auth, 2, strlen('proxy')))->toBe('proxy');
    expect(ord($auth[2 + strlen('proxy')]))->toBe(0);
    expect(ord($auth[3 + strlen('proxy')]))->toBe(0);

    fclose($server);
});

it('sends domain atyp=0x03 for SOCKS5h', function (): void {
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

        $capturedConnect = fread($client, 30);

        fwrite($client, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);

        file_put_contents('/tmp/socks5h_connect.bin', $capturedConnect);
        exit(0);
    }

    $adapter = new Socks5Adapter();
    $adapter->connect($config, 'example.com', 443);
    $adapter->close();
    pcntl_waitpid($pid, $status);

    $connect = file_get_contents('/tmp/socks5h_connect.bin');
    @unlink('/tmp/socks5h_connect.bin');

    expect(ord($connect[0]))->toBe(0x05);
    expect(ord($connect[1]))->toBe(0x01);
    expect(ord($connect[2]))->toBe(0x00);
    expect(ord($connect[3]))->toBe(0x03);
    expect(ord($connect[4]))->toBe(strlen('example.com'));
    expect(substr($connect, 5, strlen('example.com')))->toBe('example.com');

    fclose($server);
});

it('throws TransportAuthException on SOCKS5 auth failure (0xFF method)', function (): void {
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

    $adapter = new Socks5Adapter();
    $adapter->connect($config, '10.0.0.1', 80);
})->throws(TransportAuthException::class, 'rejected all authentication methods');

it('throws TransportConnectionException on SOCKS5 CONNECT failure', function (): void {
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

        fread($client, 10);

        fwrite($client, "\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new Socks5Adapter();
    $adapter->connect($config, '10.0.0.1', 80);
})->throws(TransportConnectionException::class, 'SOCKS5 connection refused');

it('throws TransportConnectionException when proxy is unreachable', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: 19999,
        credential: null,
        tls: new TlsOptions(),
        transportOptions: new SocksOptions(),
    );

    $adapter = new Socks5Adapter();
    $adapter->connect($config, '10.0.0.1', 80);
})->throws(TransportConnectionException::class, 'Cannot connect to SOCKS5 proxy');

it('close is idempotent', function (): void {
    $adapter = new Socks5Adapter();

    $adapter->close();
    $adapter->close();

    expect(true)->toBeTrue();
});
