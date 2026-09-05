<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Tool\StdinChannel;
use BAGArt\ProxyOperations\Transport\Adapters\HttpConnectAdapter;
use BAGArt\ProxyOperations\Transport\Adapters\TransportAuthException;
use BAGArt\ProxyOperations\Transport\Adapters\TransportConnectionException;
use BAGArt\ProxyOperations\Transport\HttpConnectOptions;
use BAGArt\ProxyOperations\Transport\ProxyConfig;
use BAGArt\ProxyOperations\Transport\ProxyCredentialRef;
use BAGArt\ProxyOperations\Transport\SocksOptions;
use BAGArt\ProxyOperations\Transport\TlsOptions;

it('rejects non-Http scheme', function (): void {
    $adapter = new HttpConnectAdapter;
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Socks5,
        host: '127.0.0.1',
        port: 1080,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new SocksOptions,
    );

    $adapter->connect($config, 'example.com', 443);
})->throws(InvalidArgumentException::class, 'HttpConnectAdapter requires Http or Https scheme');

it('connects through mock HTTP proxy returning 200', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);

        if ($client === false) {
            exit(1);
        }

        fread($client, 4096);

        fwrite($client, "HTTP/1.1 200 Connection Established\r\n\r\n");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new HttpConnectAdapter;
    $stream = $adapter->connect($config, 'target.example.com', 443);

    expect($stream)->not->toBeFalse();
    expect(is_resource($stream))->toBeTrue();

    $adapter->close();
    pcntl_waitpid($pid, $status);
    fclose($server);
});

it('sends Proxy-Authorization header when credentials provided', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: $port,
        credential: new ProxyCredentialRef(username: 'admin:secret', channel: new StdinChannel),
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);

        if ($client === false) {
            exit(1);
        }

        $receivedRequest = fread($client, 4096);

        fwrite($client, "HTTP/1.1 200 Connection Established\r\n\r\n");

        usleep(100_000);

        fclose($client);
        fclose($server);

        file_put_contents('/tmp/http_proxy_auth_request.txt', $receivedRequest);
        exit(0);
    }

    $adapter = new HttpConnectAdapter;
    $stream = $adapter->connect($config, 'target.example.com', 443);

    expect($stream)->not->toBeFalse();

    $adapter->close();
    pcntl_waitpid($pid, $status);

    $capturedRequest = file_get_contents('/tmp/http_proxy_auth_request.txt');
    @unlink('/tmp/http_proxy_auth_request.txt');

    expect($capturedRequest)->toContain('Proxy-Authorization: Basic ');
    expect($capturedRequest)->toContain('CONNECT target.example.com:443');

    fclose($server);
});

it('throws TransportAuthException on 407 response', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: $port,
        credential: new ProxyCredentialRef(username: 'baduser', channel: new StdinChannel),
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);

        if ($client === false) {
            exit(1);
        }

        fread($client, 4096);

        fwrite($client, "HTTP/1.1 407 Proxy Authentication Required\r\n\r\n");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new HttpConnectAdapter;
    $adapter->connect($config, 'target.example.com', 443);
})->throws(TransportAuthException::class, 'Proxy authentication failed');

it('throws TransportConnectionException on non-200 response', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $port = (int) parse_url(stream_socket_get_name($server, false), PHP_URL_PORT);
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: $port,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );

    $pid = pcntl_fork();

    if ($pid === 0) {
        $client = @stream_socket_accept($server, 5.0);

        if ($client === false) {
            exit(1);
        }

        fread($client, 4096);

        fwrite($client, "HTTP/1.1 503 Service Unavailable\r\n\r\n");

        usleep(100_000);

        fclose($client);
        fclose($server);
        exit(0);
    }

    $adapter = new HttpConnectAdapter;
    $adapter->connect($config, 'target.example.com', 443);
})->throws(TransportConnectionException::class, 'HTTP CONNECT tunnel failed with status 503');

it('throws TransportConnectionException when proxy is unreachable', function (): void {
    $config = new ProxyConfig(
        scheme: ProxyProtocol::Http,
        host: '127.0.0.1',
        port: 19999,
        credential: null,
        tls: new TlsOptions,
        transportOptions: new HttpConnectOptions,
    );

    $adapter = new HttpConnectAdapter;
    $adapter->connect($config, 'target.example.com', 443);
})->throws(TransportConnectionException::class, 'Cannot connect to HTTP proxy');

it('close is idempotent', function (): void {
    $adapter = new HttpConnectAdapter;

    $adapter->close();
    $adapter->close();

    expect(true)->toBeTrue();
});
