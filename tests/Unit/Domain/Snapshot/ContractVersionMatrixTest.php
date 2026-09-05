<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Snapshot\ContractCompatibility;
use BAGArt\ProxyOperations\Domain\Snapshot\ContractVersionMatrix;

it('exposes the plan §11.12 contract table at V1 with expected compatibility', function (): void {
    $matrix = ContractVersionMatrix::planSection1112();

    expect($matrix->entry('audit_task_result')?->compatibility)->toBe(ContractCompatibility::Additive)
        ->and($matrix->entry('parser_request_response')?->compatibility)->toBe(ContractCompatibility::Additive)
        ->and($matrix->entry('verified_proxy_projection')?->compatibility)->toBe(ContractCompatibility::Additive)
        ->and($matrix->entry('domain_event_envelope')?->compatibility)->toBe(ContractCompatibility::Additive)
        ->and($matrix->entry('proxy_selector')?->compatibility)->toBe(ContractCompatibility::Semantic)
        ->and($matrix->entry('application_api')?->compatibility)->toBe(ContractCompatibility::Versioned);

    foreach (['audit_task_result', 'parser_request_response', 'verified_proxy_projection', 'domain_event_envelope', 'proxy_selector', 'application_api'] as $name) {
        expect($matrix->entry($name)?->currentVersion)->toBe(1)
            ->and($matrix->entry($name)?->compatibleVersions)->toContain(1);
    }
});

it('returns null for unknown contracts', function (): void {
    expect(ContractVersionMatrix::planSection1112()->entry('nope'))->toBeNull();
});

it('round-trips through JSON', function (): void {
    $matrix = ContractVersionMatrix::planSection1112();

    $restored = ContractVersionMatrix::fromJson($matrix->jsonSerialize());

    expect($restored)->toEqual($matrix);
});
