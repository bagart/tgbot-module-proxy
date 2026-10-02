<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Evidence\Applicability;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceApplicability;
use BAGArt\ProxyOperations\Domain\Evidence\EvidenceType;
use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;

beforeEach(fn (): object => $this->matrix = new EvidenceApplicability());

it('covers every evidence type for every protocol (no orphan dimensions)', function (): void {
    foreach (ProxyProtocol::cases() as $protocol) {
        foreach (EvidenceType::cases() as $type) {
            expect($this->matrix->for($protocol, $type))->toBeInstanceOf(Applicability::class);
        }
    }
});

it('requires at least one evidence dimension for every protocol', function (ProxyProtocol $protocol): void {
    expect($this->matrix->requiredFor($protocol))->not->toBeEmpty();
})->with(ProxyProtocol::cases());

it('gives every evidence type at least one consuming protocol', function (EvidenceType $type): void {
    $consumers = array_filter(
        ProxyProtocol::cases(),
        fn (ProxyProtocol $protocol): bool => $this->matrix->for($protocol, $type) !== Applicability::NotApplicable,
    );

    expect($consumers)->not->toBeEmpty();
})->with(EvidenceType::cases());

it('keeps TCP liveness required for every protocol', function (ProxyProtocol $protocol): void {
    expect($this->matrix->for($protocol, EvidenceType::Tcp))->toBe(Applicability::Required);
})->with(ProxyProtocol::cases());

it('marks HTTP/UDP/DNS/Judge evidence not applicable for MTProto', function (EvidenceType $type): void {
    expect($this->matrix->for(ProxyProtocol::Mtproto, $type))->toBe(Applicability::NotApplicable);
})->with([
    'http' => EvidenceType::Http,
    'udp' => EvidenceType::Udp,
    'dns' => EvidenceType::Dns,
    'judge' => EvidenceType::Judge,
]);

it('requires Telegram connectivity exactly for MTProto', function (): void {
    foreach (ProxyProtocol::cases() as $protocol) {
        $expected = $protocol === ProxyProtocol::Mtproto ? Applicability::Required : Applicability::Optional;
        expect($this->matrix->for($protocol, EvidenceType::Telegram))->toBe($expected);
    }
});

it('allows UDP and DNS diagnostics for SOCKS5 family only', function (ProxyProtocol $protocol): void {
    $expected = in_array($protocol, [ProxyProtocol::Socks5, ProxyProtocol::Socks5h], true)
        ? Applicability::Optional
        : Applicability::NotApplicable;

    expect($this->matrix->for($protocol, EvidenceType::Udp))->toBe($expected)
        ->and($this->matrix->for($protocol, EvidenceType::Dns))->toBe($expected);
})->with(ProxyProtocol::cases());

it('excludes not-applicable types from the applicable list', function (ProxyProtocol $protocol): void {
    $applicable = $this->matrix->applicableFor($protocol);

    expect($applicable)->not->toBeEmpty();

    foreach ($applicable as $type) {
        expect($this->matrix->for($protocol, $type))->toBeIn([Applicability::Required, Applicability::Optional]);
    }

    foreach (EvidenceType::cases() as $type) {
        if (! in_array($type, $applicable, true)) {
            expect($this->matrix->for($protocol, $type))->toBe(Applicability::NotApplicable);
        }
    }
})->with(ProxyProtocol::cases());
