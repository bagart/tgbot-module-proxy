<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Policy\IpDenylist;

it('rejects every sensitive range vector of the task spec', function (string $ip): void {
    expect(IpDenylist::ssrfDefault()->contains($ip))->toBeTrue();
})->with([
    'loopback v4' => '127.0.0.1',
    'private 10/8' => '10.1.2.3',
    'private 172.16/12' => '172.16.5.5',
    'private 192.168/16' => '192.168.1.1',
    'metadata endpoint' => '169.254.169.254',
    'link-local v6 fe80::/10' => 'fe80::1',
    'ULA fc00::/7' => 'fd00::1',
    'loopback v6' => '::1',
    'IPv4-mapped loopback' => '::ffff:127.0.0.1',
    'IPv4-mapped private' => '::ffff:10.0.0.9',
]);

it('allows public addresses', function (string $ip): void {
    expect(IpDenylist::ssrfDefault()->contains($ip))->toBeFalse();
})->with([
    'public v4' => '8.8.8.8',
    'public v4 2' => '1.1.1.1',
    'public global v6' => '2606:4700::1111',
]);

it('fails closed on malformed input', function (): void {
    IpDenylist::ssrfDefault()->contains('not-an-ip');
})->throws(RuntimeException::class);

it('round-trips through JSON', function (): void {
    $list = IpDenylist::ssrfDefault();

    $restored = IpDenylist::fromJson($list->jsonSerialize());

    expect($restored)->toEqual($list);
});

it('rejects malformed ranges on deserialization', function (): void {
    $data = IpDenylist::ssrfDefault()->jsonSerialize();
    $data['ipv4Ranges'][] = '999.999.1.0/24';

    IpDenylist::fromJson($data);
})->throws(RuntimeException::class, 'invalid range');
