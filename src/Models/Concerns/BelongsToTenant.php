<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Models\Concerns;

use BAGArt\ProxyOperations\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant scoping enforcement point for Eloquent models (plan §11.22 level 1,
 * INV-006): every query is auto-filtered by the authenticated tenant and every
 * create force-fills tenant_id from the context — a tenant_id supplied through
 * attributes/input is always overwritten.
 *
 * Conventions for models using this trait: NOT NULL tenant_id column + index
 * leading all composite indexes; child tables duplicate tenant_id even though
 * derivable via FK; tenant_id never in $fillable.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $builder->where(
                $builder->getModel()->getTable().'.tenant_id',
                app(TenantContext::class)->id(),
            );
        });

        static::creating(function (Model $model): void {
            $model->forceFill(['tenant_id' => app(TenantContext::class)->id()]);
        });
    }
}
