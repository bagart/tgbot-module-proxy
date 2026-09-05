<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models;

use BAGArt\ProxyOperations\Database\Factories\ProxyPolicyFactory;
use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * WorkspacePolicy (plan §§11.18 п.7, 11.21, 11.29): the workspace-editable
 * operational settings — quotas instead of roles (§4), retention ("no
 * deletion" by default, §10.11), export rules, UI flags and politeness budget
 * placeholders. This is NOT what gets snapshotted into jobs —
 * AuditPolicySnapshot is a separate Stage-0 DTO built later from this row plus
 * system dictionaries (plan §11.35 п.7).
 *
 * Defaults are resolved at creation time from `config('proxy-operations.*')`
 * over the model's last-resort fallbacks; an existing row never mutates when
 * the config changes afterwards.
 *
 * @property string $id
 * @property int $tenant_id
 * @property string $version
 * @property array<string, int> $quotas
 * @property array<string, mixed> $politeness
 * @property array<string, bool> $retention
 * @property array<string, bool|int> $export_rules
 * @property array<string, bool> $ui_flags
 */
final class ProxyPolicy extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    /**
     * Last-resort quota values used only for keys absent from
     * `config('proxy-operations.quotas')` (read defensively — the config may
     * be extended/replaced independently).
     *
     * @var array<string, int>
     */
    public const QUOTA_FALLBACKS = [
        'max_endpoints' => 1000,
        'jobs_per_day' => 500,
        'concurrent_jobs' => 2,
        'max_import_file_bytes' => 10_485_760,
    ];

    public const EXPORT_RATE_LIMIT_FALLBACK = 100;

    protected $fillable = [
        'version',
        'quotas',
        'politeness',
        'retention',
        'export_rules',
        'ui_flags',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $policy): void {
            foreach (self::defaultsForTenant() as $key => $default) {
                if ($policy->getAttribute($key) === null) {
                    $policy->setAttribute($key, $default);
                }
            }
        });
    }

    /**
     * Lazy workspace creation on first access (§4): returns the tenant's
     * policy row, creating it with resolved defaults if missing.
     */
    public static function forCurrentTenant(): self
    {
        $existing = self::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return self::create(self::defaultsForTenant());
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created the row first — it wins.
            return self::query()->firstOrFail();
        }
    }

    /**
     * Resolved creation-time defaults: config values over fallback constants.
     *
     * @return array<string, mixed>
     */
    public static function defaultsForTenant(): array
    {
        $configQuotas = (array) config('proxy-operations.quotas', []);
        $quotas = [];

        foreach (self::QUOTA_FALLBACKS as $key => $fallback) {
            $quotas[$key] = isset($configQuotas[$key]) ? (int) $configQuotas[$key] : $fallback;
        }

        $configRetention = (array) config('proxy-operations.retention', []);
        $configExport = (array) config('proxy-operations.export_rules', []);
        $configUi = (array) config('proxy-operations.ui_flags', []);

        return [
            'version' => 'v1',
            'quotas' => $quotas,
            'politeness' => [],
            'retention' => [
                'enabled' => (bool) ($configRetention['enabled'] ?? false),
            ],
            'export_rules' => [
                'with_credentials_opt_in' => (bool) ($configExport['with_credentials_opt_in'] ?? false),
                'rate_limit_per_day' => (int) ($configExport['rate_limit_per_day'] ?? self::EXPORT_RATE_LIMIT_FALLBACK),
            ],
            'ui_flags' => [
                'web_panel_enabled' => (bool) ($configUi['web_panel_enabled'] ?? false),
            ],
        ];
    }

    protected function casts(): array
    {
        return [
            'quotas' => 'array',
            'politeness' => 'array',
            'retention' => 'array',
            'export_rules' => 'array',
            'ui_flags' => 'array',
        ];
    }

    protected static function newFactory(): Factory
    {
        return ProxyPolicyFactory::new();
    }
}
