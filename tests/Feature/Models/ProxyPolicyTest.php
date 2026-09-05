<?php

declare(strict_types=1);

use App\Models\User;
use BAGArt\ProxyOperations\Models\ProxyPolicy;
use BAGArt\ProxyOperations\Tenancy\TenantContext;
use BAGArt\ProxyOperations\Tenancy\TenantNotResolvedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function createPolicyTenantUser(): User
{
    return User::factory()->create();
}

function policyTenant(): int
{
    $userId = createPolicyTenantUser()->id;
    app(TenantContext::class)->set($userId);

    return $userId;
}

it('creates the proxy_policies table with expected columns and a tenant-unique index', function (): void {
    expect(Schema::hasTable('proxy_policies'))->toBeTrue()
        ->and(Schema::hasColumns('proxy_policies', [
            'id', 'tenant_id', 'version',
            'quotas', 'politeness', 'retention', 'export_rules', 'ui_flags',
            'created_at', 'updated_at',
        ]))->toBeTrue();

    $uniqueIndex = collect(DB::select("PRAGMA index_list('proxy_policies')"))
        ->map(fn (object $index): array => [
            'unique' => (bool) $index->unique,
            'columns' => collect(DB::select("PRAGMA index_info('{$index->name}')"))->pluck('name')->all(),
        ])
        ->first(fn (array $index): bool => $index['unique'] && $index['columns'] === ['tenant_id']);

    expect($uniqueIndex)->not->toBeNull();
});

it('enforces one policy row per tenant at DB level but allows one per tenant', function (): void {
    $tenantId = policyTenant();
    $mine = ProxyPolicy::factory()->create();

    expect($mine->tenant_id)->toBe($tenantId)
        ->and($mine->version)->toBe('v1');

    expect(fn (): ProxyPolicy => ProxyPolicy::factory()->create())
        ->toThrow(UniqueConstraintViolationException::class);

    app(TenantContext::class)->set(createPolicyTenantUser()->id);
    ProxyPolicy::factory()->create();

    // Global scope hides the other tenant's row; raw table count sees both.
    expect(ProxyPolicy::query()->count())->toBe(1)
        ->and(DB::table('proxy_policies')->count())->toBe(2);
});

it('creates the workspace policy lazily once via forCurrentTenant and returns the same row after', function (): void {
    policyTenant();

    $first = ProxyPolicy::forCurrentTenant();
    $second = ProxyPolicy::forCurrentTenant();

    expect($first->exists)->toBeTrue()
        ->and($second->getKey())->toBe($first->getKey())
        ->and(ProxyPolicy::query()->count())->toBe(1);
});

it('resolves creation defaults as config values over fallbacks for missing keys', function (): void {
    policyTenant();

    config([
        'proxy-operations.quotas.max_endpoints' => 555,
        'proxy-operations.quotas.max_import_file_bytes' => '2048',
        'proxy-operations.export_rules.rate_limit_per_day' => 7,
    ]);

    $row = ProxyPolicy::forCurrentTenant();

    expect($row->quotas['max_endpoints'])->toBe(555)
        ->and($row->quotas['max_import_file_bytes'])->toBe(2048)
        ->and($row->quotas['jobs_per_day'])->toBe(ProxyPolicy::QUOTA_FALLBACKS['jobs_per_day'])
        ->and($row->quotas['concurrent_jobs'])->toBe(ProxyPolicy::QUOTA_FALLBACKS['concurrent_jobs'])
        ->and($row->export_rules)->toBe([
            'with_credentials_opt_in' => false,
            'rate_limit_per_day' => 7,
        ])
        ->and($row->retention)->toBe(['enabled' => false])
        ->and($row->ui_flags)->toBe(['web_panel_enabled' => false])
        ->and($row->politeness)->toBe([]);
});

it('matches defaults to config values when every quota key is configured', function (): void {
    policyTenant();

    config(['proxy-operations.quotas' => [
        'max_endpoints' => 10,
        'jobs_per_day' => 20,
        'concurrent_jobs' => 3,
        'max_import_file_bytes' => 4096,
    ]]);

    $row = ProxyPolicy::forCurrentTenant();

    expect($row->quotas)->toBe([
        'max_endpoints' => 10,
        'jobs_per_day' => 20,
        'concurrent_jobs' => 3,
        'max_import_file_bytes' => 4096,
    ]);
});

it('does not mutate an existing row when the config changes afterwards', function (): void {
    policyTenant();

    $row = ProxyPolicy::forCurrentTenant();
    $originalQuotas = $row->quotas;
    $originalRetention = $row->retention;

    config([
        'proxy-operations.quotas' => [
            'max_endpoints' => 999_999,
            'jobs_per_day' => 999_999,
            'concurrent_jobs' => 99,
            'max_import_file_bytes' => 1,
        ],
        'proxy-operations.retention.enabled' => true,
    ]);

    expect($row->refresh()->quotas)->toBe($originalQuotas)
        ->and($row->refresh()->retention)->toBe($originalRetention);
});

it('produces factory rows with sane typed JSON payloads', function (): void {
    policyTenant();

    $row = ProxyPolicy::factory()->create();

    expect($row->quotas)->toBeArray()
        ->and($row->quotas)->toHaveKeys(array_keys(ProxyPolicy::QUOTA_FALLBACKS))
        ->and($row->retention['enabled'])->toBeBool()
        ->and($row->ui_flags['web_panel_enabled'])->toBeBool()
        ->and($row->export_rules['with_credentials_opt_in'])->toBeBool()
        ->and($row->export_rules['rate_limit_per_day'])->toBeInt()
        ->and($row->politeness)->toBe([]);
});

it('hides other tenants policies from queries', function (): void {
    $context = app(TenantContext::class);

    $context->set(policyTenant());
    $mine = ProxyPolicy::factory()->create();

    $context->set(policyTenant());

    expect(ProxyPolicy::query()->whereKey($mine->id)->exists())->toBeFalse()
        ->and(ProxyPolicy::query()->count())->toBe(0);
});

it('throws on queries and creates without a resolved tenant instead of falling back to unscoped', function (): void {
    app(TenantContext::class)->forget();

    expect(fn () => ProxyPolicy::query()->get())->toThrow(TenantNotResolvedException::class);

    app(TenantContext::class)->forget();

    expect(fn (): ProxyPolicy => ProxyPolicy::factory()->create())
        ->toThrow(TenantNotResolvedException::class);
});
