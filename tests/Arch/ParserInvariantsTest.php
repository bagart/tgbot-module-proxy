<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\TransportKind;
use BAGArt\ProxyOperations\Domain\Parsing\ParsedEntry;
use BAGArt\ProxyOperations\Parser\ImportProxiesService;

function parsingDir(): string
{
    return dirname(__DIR__, 2).'/src/Domain/Parsing';
}

function parsingPhpFiles(): array
{
    static $files = null;

    if ($files !== null) {
        return $files;
    }

    $files = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        parsingDir(),
        FilesystemIterator::SKIP_DOTS,
    ));

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

function parsingStripComments(string $code): string
{
    $withoutBlocks = (string) preg_replace('/\/\*.*?\*\//s', '', $code);

    return (string) preg_replace('/^\s*\/\/[^\n]*$/m', '', $withoutBlocks);
}

it('INV-007: parser never references encryption classes', function (): void {
    $forbidden = ['CredentialEncryptor', 'KekProvider', 'EncryptedField', 'WorkspaceDek', 'PROXY_ENC_KEY'];

    $violations = [];

    foreach (parsingPhpFiles() as $path => $contents) {
        $code = parsingStripComments($contents);
        $relativePath = basename($path);

        foreach ($forbidden as $symbol) {
            if (str_contains($code, $symbol)) {
                $violations[] = "{$relativePath} references {$symbol}";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('INV-007: ParsedEntry jsonSerialize omits secret field', function (): void {
    $entryClass = new ReflectionClass(ParsedEntry::class);
    $serializeMethod = $entryClass->getMethod('jsonSerialize');
    $source = (string) file_get_contents($entryClass->getFileName());

    $serializeBody = substr($source, strpos($source, 'public function jsonSerialize'));
    $serializeBody = substr($serializeBody, 0, strpos($serializeBody, '}'));

    expect(str_contains($serializeBody, "'secret'"))->toBeFalse('ParsedEntry::jsonSerialize() must not include secret');
});

it('INV-008: ProxyProtocol::Mtproto is not treated as a transport', function (): void {
    expect(TransportKind::tryFrom('mtproto'))->toBeNull('mtproto must not be a TransportKind case');

    $mtprotoCases = array_filter(TransportKind::cases(), fn ($c) => str_contains(strtolower($c->value), 'mtproto'));
    expect($mtprotoCases)->toBe([]);
});

it('INV-008: Domain\Parsing contains no transport-level code', function (): void {
    $violations = [];

    foreach (parsingPhpFiles() as $path => $contents) {
        $code = parsingStripComments($contents);
        $relativePath = basename($path);

        if (preg_match('/\bconnect\s*\(/', $code) === 1) {
            $violations[] = "{$relativePath} contains connect() call";
        }

        if (preg_match('/TransportKind/', $code) === 1) {
            $violations[] = "{$relativePath} references TransportKind";
        }
    }

    expect($violations)->toBe([]);
});

it('INV-006: Domain\Parsing has no TenantContext reference', function (): void {
    $violations = [];

    foreach (parsingPhpFiles() as $path => $contents) {
        $code = parsingStripComments($contents);
        $relativePath = basename($path);

        if (preg_match('/TenantContext/', $code) === 1) {
            $violations[] = "{$relativePath} references TenantContext";
        }

        if (preg_match('/tenant_id/', $code) === 1) {
            $violations[] = "{$relativePath} references tenant_id";
        }
    }

    expect($violations)->toBe([]);
});

it('INV-006: ImportProxiesService derives tenant_id from TenantContext, not from parsed input', function (): void {
    $serviceClass = new ReflectionClass(ImportProxiesService::class);
    $source = (string) file_get_contents($serviceClass->getFileName());

    $executeMethod = substr($source, strpos($source, 'public function execute'));
    $executeMethod = substr($executeMethod, 0, strpos($executeMethod, 'private function stageRawFeedEntries'));

    expect(str_contains($executeMethod, '$this->tenant->id()'))->toBeTrue('execute() must derive tenant_id from TenantContext');
});
