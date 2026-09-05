# T00 — Module skeleton

Plan ref: §7. Depends on: nothing. Blocks everything.

## Goal

Minimal self-testable library package inside `misc/BAGArt/telegram-bot-proxy-module/`.

## Create

- `composer.json`: name `bagart/telegram-bot-proxy-module`, type `library`, license MIT,
  `require: { "php": "^8.5" }`, PSR-4 `BAGArt\\ProxyOperations\\` → `src/`,
  autoload-dev `BAGArt\\ProxyOperations\\Tests\\` → `tests/`,
  script `test`: `@php ../../../vendor/bin/pest`, `minimum-stability: dev`, `prefer-stable: true`.
  No path repositories needed at Stage 0 (pure PHP, zero bagart deps).
- `phpunit.xml.dist`: bootstrap `../../../vendor/autoload.php`, suites Unit (`tests/Unit`),
  `<source><directory>src</directory></source>`.
- `Readme.md` stub: module purpose, pointer to `docs/proxy-operations/plan.md`.
- `tests/Unit/SmokeTest.php` (Pest): asserts the autoloader resolves a placeholder class.
- `src/` must exist with at least one real class so autoload is valid.

## Acceptance

- `composer validate` passes.
- From module dir `composer test` runs Pest green.
- No Laravel dependency introduced at Stage 0.
