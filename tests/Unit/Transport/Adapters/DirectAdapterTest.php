<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Transport\Adapters\DirectAdapter;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\TlsOptions;

it('connects directly to a TCP server without proxy', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions(verifyPeer: false),
        transportOptions: new HttpConnectOptions,
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);
        if ($client === false) {
            exit(1);
        }

        fwrite($client, "HELLO\n");
        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new DirectAdapter;
    $stream = $adapter->connect($config, '127.0.0.1', $port);

    expect($stream)->not->toBeFalse();
    expect(is_resource($stream))->toBeTrue();

    $data = fread($stream, 1024);
    expect($data)->toBe("HELLO\n");

    $adapter->close();
    pcntl_waitpid($pid, $status);
    fclose($server);
});

it('throws TransportConnectionException when target is unreachable', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: 19999,
        credential: null,
        tls: new TlsOptions(verifyPeer: false),
        transportOptions: new HttpConnectOptions,
    );

    $adapter = new DirectAdapter;
    $adapter->connect($config, '127.0.0.1', 19999);
})->throws(TransportConnectionException::class, 'Direct connection');

it('close is idempotent', function (): void {
    $adapter = new DirectAdapter;

    $adapter->close();
    $adapter->close();

    expect(true)->toBeTrue();
});

it('closes previous stream on reconnect', function (): void {
    $server1 = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port1 = (int) parse_url(stream_socket_get_name($server1, false), PHP_URL_PORT);

    $server2 = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $port2 = (int) parse_url(stream_socket_get_name($server2, false), PHP_URL_PORT);

    $config1 = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: $port1,
        credential: null,
        tls: new TlsOptions(verifyPeer: false),
        transportOptions: new HttpConnectOptions,
    );

    $config2 = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: $port2,
        credential: null,
        tls: new TlsOptions(verifyPeer: false),
        transportOptions: new HttpConnectOptions,
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $c1 = @stream_socket_accept($server1, 5.0);
        $c2 = @stream_socket_accept($server2, 5.0);

        if ($c1) {
            usleep(50_000);
            fclose($c1);
        }
        if ($c2) {
            usleep(150_000);
            fclose($c2);
        }

        fclose($server1);
        fclose($server2);
        exit(0);
    }

    $adapter = new DirectAdapter;

    $stream1 = $adapter->connect($config1, '127.0.0.1', $port1);
    expect($stream1)->not->toBeFalse();

    $stream2 = $adapter->connect($config2, '127.0.0.1', $port2);
    expect($stream2)->not->toBeFalse();
    expect($stream2)->not->toBe($stream1);

    $adapter->close();
    pcntl_waitpid($pid, $status);
});
