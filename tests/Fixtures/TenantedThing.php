<?php

declare(strict_types=1);

namespace BAGArt\ProxyOperations\Tests\Fixtures;

use BAGArt\ProxyOperations\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Throwaway test model exercising the BelongsToTenant enforcement point.
 * Not part of the module domain; backed by a test-local sqlite table.
 */
final class TenantedThing extends Model
{
    use BelongsToTenant;

    protected $table = 'tenancy_test_things';

    /** tenant_id is never mass-assignable (T02 model convention). */
    protected $guarded = ['tenant_id'];
}
