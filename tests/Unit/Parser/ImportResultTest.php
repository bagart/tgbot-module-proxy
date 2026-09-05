<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Parsing\ImportResult;
use BAGArt\ProxyOperations\Domain\Parsing\ImportResultError;
use BAGArt\ProxyOperations\Domain\Parsing\ParseError;
use BAGArt\ProxyOperations\Domain\Parsing\ParseErrorCode;

it('roundtrips ImportResult through jsonSerialize and fromJson', function (): void {
    $errors = [
        new ImportResultError(line: 1, code: 'empty_line', detail: null),
        new ImportResultError(line: 3, code: 'non_vpn_rejected', detail: 'VPN protocol outside scope'),
    ];

    $original = new ImportResult(
        totalLines: 5,
        created: 2,
        skipped: 1,
        parseErrors: 2,
        staged: 4,
        errors: $errors,
        importBatchId: '550e8400-e29b-41d4-a716-446655440000',
    );

    $json = $original->jsonSerialize();

    expect($json['totalLines'])->toBe(5)
        ->and($json['created'])->toBe(2)
        ->and($json['skipped'])->toBe(1)
        ->and($json['parseErrors'])->toBe(2)
        ->and($json['staged'])->toBe(4)
        ->and($json['importBatchId'])->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($json['schemaVersion'])->toBe(1)
        ->and($json['errors'])->toHaveCount(2);

    $restored = ImportResult::fromJson($json);

    expect($restored->totalLines)->toBe(5)
        ->and($restored->created)->toBe(2)
        ->and($restored->skipped)->toBe(1)
        ->and($restored->parseErrors)->toBe(2)
        ->and($restored->staged)->toBe(4)
        ->and($restored->importBatchId)->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($restored->errors)->toHaveCount(2)
        ->and($restored->errors[0]->line)->toBe(1)
        ->and($restored->errors[0]->code)->toBe('empty_line')
        ->and($restored->errors[1]->line)->toBe(3)
        ->and($restored->errors[1]->code)->toBe('non_vpn_rejected');
});

it('roundtrips ImportResult with empty errors list', function (): void {
    $original = new ImportResult(
        totalLines: 1,
        created: 1,
        skipped: 0,
        parseErrors: 0,
        staged: 1,
        errors: [],
        importBatchId: 'test-batch-id',
    );

    $json = $original->jsonSerialize();
    $restored = ImportResult::fromJson($json);

    expect($restored->errors)->toBe([])
        ->and($restored->importBatchId)->toBe('test-batch-id');
});

it('roundtrips ImportResultError through jsonSerialize and fromJson', function (): void {
    $original = new ImportResultError(
        line: 42,
        code: 'invalid_port',
        detail: 'Port out of range',
    );

    $json = $original->jsonSerialize();
    $restored = ImportResultError::fromJson($json);

    expect($restored->line)->toBe(42)
        ->and($restored->code)->toBe('invalid_port')
        ->and($restored->detail)->toBe('Port out of range');
});

it('creates ImportResultError from ParseError via factory method', function (): void {
    $parseError = new ParseError(
        line: 5,
        rawLine: 'bad-line',
        code: ParseErrorCode::InvalidFormat,
        detail: 'Could not parse',
    );

    $resultError = ImportResultError::fromParseError($parseError);

    expect($resultError->line)->toBe(5)
        ->and($resultError->code)->toBe('invalid_format')
        ->and($resultError->detail)->toBe('Could not parse');
});
