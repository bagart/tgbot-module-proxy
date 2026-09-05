# T01 — Module Laravel skeleton + DB test harness

Plan ref: §7 rows #1 (infrastructure part) and #23/IMPROVE#5; plan §§4, 11.21–11.22.
Depends on: Stage 0 done (T00–T94). All of T02–T08 depend on this file.

## Goal

Give the package its Laravel-facing half, following sibling-module layout
(`telegram-platform-management`, `tgbot-module-antispam`): models in
`src/Models/`, migrations in `database/migrations/` at module root, factories in
`database/factories/`, provider at `src/ProxyOperationsServiceProvider.php`,
config in `config/`. There is no `src/Laravel/` convention among siblings —
mirror what exists instead.

Also boot the DB test harness exactly like the antispam module: host
`vendor/bin/pest` run from the module dir; `pestphp/pest-plugin-laravel`
(host vendor) auto-boots the host app and auto-migrates the in-memory SQLite
database declared in the module `phpunit.xml.dist`.

## Deliverables (file-by-file)

- `src/ProxyOperationsServiceProvider.php` — `register()`:
  `mergeConfigFrom(__DIR__.'/../config/proxy-operations.php', 'proxy-operations')`;
  `boot()`: `$this->loadMigrationsFrom(__DIR__.'/../database/migrations')`.
  Mirror `TelegramBotAntispamServiceProvider` structure (`declare(strict_types=1)` etc.).
- `config/proxy-operations.php` — module settings in config, not env (platform rule).
  Sections (values are defaults consumed by later tasks):
  - `tenancy` => [] reserved;
  - `encryption` => ['kek' => env('PROXY_ENC_KEY'), 'fallback_to_app_key' => true,
    'algorithm' => 'aes-256-gcm'] (env read once here only; used by T08);
  - `quotas` => default per-workspace limits placeholder (used by T07);
  - `retention` => ['enabled' => false] ("no deletion" decision, §11.15 п.6);
  - `probe_defaults` => [] reserved.
- `database/migrations/` + `database/factories/` directories created (empty is fine;
  later tasks fill them). Add `.gitignore`-free placeholder via first real migration.
- `phpunit.xml.dist` — extend current Unit/Arch file with a Feature suite and the
  env block copied from `tgbot-module-antispam/phpunit.xml.dist`
  (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`,
  `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `APP_ENV=testing`, ...).
- `tests/Pest.php` — add `pest()->extend(Tests\TestCase::class)->use(RefreshDatabase::class)->in('Feature')`-style
  wiring equivalent to the host `tests/Pest.php`; if the package cannot reference host
  `Tests\TestCase`, create `tests/TestCase.php` that mirrors the host one
  (check `tests/TestCase.php` at repo root and copy its bootstrap approach).
  Note: antispam has no local Pest.php/TestCase and works standalone because
  pest-plugin-laravel boots the app; verify empirically first and only add local
  TestCase if a plain feature test fails without it.
- Host integration steps (outside module, required for tests to pass):
  1. Add `BAGArt\ProxyOperations\ProxyOperationsServiceProvider::class` to
     `bootstrap/providers.php` (one line, same as other modules).
  2. Add PSR-4 mappings to host `composer.json` autoload:
     `"BAGArt\\ProxyOperations\\Database\\Factories\\": "misc/BAGArt/telegram-bot-proxy-module/database/factories/"`,
     then `composer dump-autoload` (run from WSL shell).

## Conventions

- PHP ^8.5, `declare(strict_types=1)`, LF endings, English, no git operations.
- Do not register anything in `config('telegram.modules_providers')` yet — no
  `TgModuleContract` in this stage.
- Lazy everything: provider must not connect to Redis/DB at boot.

## Tests

- `tests/Feature/SkeletonTest.php`: app() booted, `config('proxy-operations.encryption.algorithm')`
  returns expected value; `Schema::hasTable('users')` true (proves migrations ran);
  a throwaway migration-less model roundtrip is NOT needed here.

## Verify

```
timeout 120 ../../../vendor/bin/pest --testsuite Feature   # green
timeout 120 ../../../vendor/bin/pest                        # whole suite green
```

## Out-of-scope

- All domain tables/models (T02+), bot/UI surfaces, queue wiring.
- `composer.prod.json` overlay changes (module still has no runtime deps beyond php).
