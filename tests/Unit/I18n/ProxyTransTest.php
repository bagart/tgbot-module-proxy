<?php

declare(strict_types=1);

use BAGArt\ProxyOperations\I18n\ProxyTrans;

it('returns English translation for known key', function (): void {
    ProxyTrans::setLocale('en');
    $result = ProxyTrans::get('proxy.bot.start');

    expect($result)->toContain('Welcome');
});

it('returns Russian translation for known key', function (): void {
    ProxyTrans::setLocale('ru');
    $result = ProxyTrans::get('proxy.bot.start');

    expect($result)->toContain('Добро пожаловать');
});

it('returns French translation for known key', function (): void {
    ProxyTrans::setLocale('fr');
    $result = ProxyTrans::get('proxy.bot.start');

    expect($result)->toContain('Bienvenue');
});

it('returns Spanish translation for known key', function (): void {
    ProxyTrans::setLocale('es');
    $result = ProxyTrans::get('proxy.bot.start');

    expect($result)->toContain('Bienvenido');
});

it('returns Chinese translation for known key', function (): void {
    ProxyTrans::setLocale('zh');
    $result = ProxyTrans::get('proxy.bot.start');

    expect($result)->toContain('欢迎');
});

it('falls back to English for missing locale file', function (): void {
    ProxyTrans::setLocale('de');
    $result = ProxyTrans::get('proxy.bot.start');

    expect($result)->toContain('Welcome');
});

it('returns key itself for unknown key', function (): void {
    ProxyTrans::setLocale('en');
    $result = ProxyTrans::get('proxy.nonexistent.key');

    expect($result)->toBe('proxy.nonexistent.key');
});

it('replaces placeholders', function (): void {
    ProxyTrans::setLocale('en');
    $result = ProxyTrans::get('proxy.import.success', ['imported' => 5, 'skipped' => 2]);

    expect($result)->toContain('5')
        ->and($result)->toContain('2');
});

it('error helper resolves error codes', function (): void {
    ProxyTrans::setLocale('en');
    $result = ProxyTrans::error('QUOTA_EXCEEDED');

    expect($result)->toContain('quota');
});

it('all English keys exist in all language files', function (): void {
    $langPath = dirname(__DIR__, 3).'/lang';
    $en = json_decode(file_get_contents($langPath.'/en.json'), true);
    $locales = ['ru', 'fr', 'es', 'zh'];

    foreach ($locales as $locale) {
        $lang = json_decode(file_get_contents($langPath."/{$locale}.json"), true);
        $missing = array_diff_key($en, $lang);

        expect($missing, "Missing keys in {$locale}")->toBeEmpty();
    }
});
