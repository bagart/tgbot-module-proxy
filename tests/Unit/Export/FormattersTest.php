<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\Domain\Identity\ProxyProtocol;
use BAGArt\ProxyOperations\Export\ClashFormatter;
use BAGArt\ProxyOperations\Export\CsvExportFormatter;
use BAGArt\ProxyOperations\Export\CurlFormatter;
use BAGArt\ProxyOperations\Export\ExportView;
use BAGArt\ProxyOperations\Export\JsonExportFormatter;
use BAGArt\ProxyOperations\Export\ProxychainsFormatter;
use BAGArt\ProxyOperations\Export\TxtExportFormatter;

function views(): array
{
    return [
        new ExportView('a1', ProxyProtocol::Socks5, '1.2.3.4', 1080, 'user:pass', 0.9, 'WORKING', true, 'NL'),
        new ExportView('a2', ProxyProtocol::Http, '5.6.7.8', 8080, '', 0.5, 'DEGRADED', false, 'DE'),
    ];
}

it('TXT host_port variant', function (): void {
    $f = new TxtExportFormatter('host_port');
    $out = $f->format(views());
    expect($out)->toContain('1.2.3.4:1080')
        ->and($out)->toContain('5.6.7.8:8080');
});

it('TXT scheme_user_pass variant', function (): void {
    $f = new TxtExportFormatter('scheme_user_pass');
    $out = $f->format(views());
    expect($out)->toContain('socks5://user:pass@1.2.3.4:1080')
        ->and($out)->toContain('http://5.6.7.8:8080');
});

it('CSV has headers and rows', function (): void {
    $f = new CsvExportFormatter;
    $out = $f->format(views());
    $lines = array_filter(explode("\n", trim($out)));
    expect($lines)->toHaveCount(3); // header + 2 rows
});

it('JSON is valid and contains all fields', function (): void {
    $f = new JsonExportFormatter;
    $out = $f->format(views());
    $data = json_decode($out, true);
    expect($data)->toHaveCount(2)
        ->and($data[0]['protocol'])->toBe('socks5')
        ->and($data[0]['host'])->toBe('1.2.3.4');
});

it('Proxychains generates valid conf', function (): void {
    $f = new ProxychainsFormatter;
    $out = $f->format(views());
    expect($out)->toContain('[ProxyList]')
        ->and($out)->toContain('socks5 1.2.3.4 1080')
        ->and($out)->toContain('http 5.6.7.8 8080');
});

it('Curl generates curl commands', function (): void {
    $f = new CurlFormatter;
    $out = $f->format(views());
    expect($out)->toContain("curl -x 'socks5h://user%3Apass@1.2.3.4:1080'")
        ->and($out)->toContain("curl -x 'http://5.6.7.8:8080'");
});

it('Clash generates valid YAML structure', function (): void {
    $f = new ClashFormatter;
    $out = $f->format(views());
    expect($out)->toContain('proxies:')
        ->and($out)->toContain('type: "socks5"')
        ->and($out)->toContain('server: "1.2.3.4"')
        ->and($out)->toContain('port: 1080');
});
