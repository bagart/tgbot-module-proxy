<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Tool\CredentialChannel;
use BAGArt\ProxyOperations\Tool\CredentialDeliveryMode;
use BAGArt\ProxyOperations\Tool\FileDescriptorChannel;
use BAGArt\ProxyOperations\Tool\StdinChannel;

it('offers stdin and file descriptor delivery modes per plan §11.39 п.7', function (): void {
    expect(CredentialDeliveryMode::cases())->toHaveCount(2);
    expect((new StdinChannel())->mode())->toBe(CredentialDeliveryMode::Stdin);
    expect((new FileDescriptorChannel(3))->mode())->toBe(CredentialDeliveryMode::FileDescriptor);
});

it('exposes no string-typed secret accessor anywhere in the channel hierarchy', function (): void {
    $types = [
        new ReflectionClass(CredentialChannel::class),
        new ReflectionClass(StdinChannel::class),
        new ReflectionClass(FileDescriptorChannel::class),
    ];

    foreach ($types as $type) {
        foreach ($type->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();

            expect($returnType instanceof ReflectionNamedType && $returnType->getName() === 'string')
                ->toBeFalse("{$type->getShortName()}::{$method->getName()}() must not return a string");

            foreach ($method->getParameters() as $parameter) {
                $parameterType = $parameter->getType();

                expect($parameterType instanceof ReflectionNamedType && $parameterType->getName() === 'string' && str_contains(strtolower($parameter->getName()), 'secret'))
                    ->toBeFalse("{$type->getShortName()}::{$method->getName()}() must not take a secret parameter");
            }
        }
    }
});

it('carries only delivery metadata, never credential material', function (): void {
    $stdin = new StdinChannel();
    $fd = new FileDescriptorChannel(4);

    expect($stdin)->toBeInstanceOf(CredentialChannel::class)
        ->and(get_object_vars($stdin))->toBe([])
        ->and(get_object_vars($fd))->toBe(['fileDescriptor' => 4]);
});

it('rejects negative file descriptors', function (): void {
    new FileDescriptorChannel(-1);
})->throws(InvalidArgumentException::class, 'File descriptor must be >= 0.');
