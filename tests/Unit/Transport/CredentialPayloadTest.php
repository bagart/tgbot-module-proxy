<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Transport\CredentialPayload;

it('holds username and secret', function (): void {
    $payload = new CredentialPayload(username: 'user1', secret: 's3cret');

    expect($payload->username)->toBe('user1');
    expect($payload->secret)->toBe('s3cret');
});

it('accepts null username', function (): void {
    $payload = new CredentialPayload(username: null, secret: 's3cret');

    expect($payload->username)->toBeNull();
    expect($payload->secret)->toBe('s3cret');
});

it('does not implement JsonSerializable', function (): void {
    $payload = new CredentialPayload(username: 'user1', secret: 's3cret');

    expect($payload)->not->toBeInstanceOf(JsonSerializable::class);
});

it('has no toArray or jsonSerialize method', function (): void {
    $payload = new CredentialPayload(username: 'user1', secret: 's3cret');

    expect(method_exists($payload, 'jsonSerialize'))->toBeFalse();
    expect(method_exists($payload, 'toArray'))->toBeFalse();
});

it('is readonly and cannot be mutated', function (): void {
    $payload = new CredentialPayload(username: 'user1', secret: 's3cret');

    expect($payload->username)->toBe('user1');
    expect($payload->secret)->toBe('s3cret');
});
